<?php

namespace Drush\Commands\core;

use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal;
use Drush\Backend\BackendPathEvaluator;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Consolidation\SiteAlias\HostPath;
use Drush\Sql\SqlBase;
use Drush\Utils\FsUtils;
use Exception;
use PharData;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

/**
 * Class ArchiveRestoreCommands.
 */
class ArchiveRestoreCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use SiteAliasManagerAwareTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var array
     */
    private array $siteStatus;

    /**
     * @var string
     */
    private string $extractedPath;

    /**
     * @var null|string
     */
    private ?string $destinationPath = null;

    /**
     * @var bool
     */
    private bool $autodetectDestination = true;

    private const COMPONENT_CODE = 'code';

    private const COMPONENT_FILES = 'files';

    private const COMPONENT_DATABASE = 'database';
    private const SQL_DUMP_FILE_NAME = 'database.sql';

    private const TEMP_DIR_NAME = 'uncompressed';

    /**
     * Restore (import) your code, files, and database.
     *
     * @command archive:restore
     * @aliases arr
     *
     * @option destination_path The base path to restore the code/files into.
     * @option overwrite Overwrite files if exists when un-compressing an archive.
     * @option code Import code.
     * @option code_source_path Import code from specified directory. Has higher priority over "path" argument.
     * @option files Import Drupal files.
     * @option files_source_path Import Drupal files from specified directory. Has higher priority over "path" argument.
     * @option files_destination_relative_path Import Drupal files into specified directory.
     * @option db Import database.
     * @option db_source_path Import database from specified dump file. Has higher priority over "path" argument.
     *
     * @optionset_sql
     * @optionset_table_selection
     *
     * @bootstrap none
     *
     * @param string|null $path
     *   The full path to a single archive file (*.tar.gz) or a directory with components to import.
     *   May contain the following components generated by `archive:dump` command:
     *   1) code ("code" directory);
     *   2) database dump file ("database/database.sql" file);
     *   3) Drupal files ("files" directory).
     * @param string|null $site
     *   Destination site alias. Defaults to @self.
     * @param array $options
     *
     * @throws \Exception
     */
    public function restore(
        string $path = null,
        ?string $site = null,
        array $options = [
            'destination_path' => null,
            'overwrite' => false,
            'code' => false,
            'code_source_path' => null,
            'files' => false,
            'files_source_path' => null,
            'files_destination_relative_path' => null,
            'db' => false,
            'db_source_path' => null,
        ]
    ): void {
        $siteAlias = $this->getSiteAlias($site);
        if (!$siteAlias->isLocal()) {
            throw new Exception(
                dt(
                    'Could not restore archive !path into site !site: restoring an archive into a local site is not supported.',
                    ['!path' => $path, '!site' => $site]
                )
            );
        }

        $this->prepareTempDir();

        $extractDir = null;
        if (null !== $path) {
            $extractDir = is_dir($path) ? $path : $this->extractArchive($path, $options);
        }

        if (!$options['code'] && !$options['files'] && !$options['db']) {
            $options['code'] = $options['files'] = $options['db'] = true;
        }

        foreach (['code' => 'code', 'db' => 'database', 'files' => 'files'] as $component => $label) {
            if (!$options[$component]) {
                continue;
            }

            // Validate requested components have sources.
            if (null === $extractDir && null === $options[$component . '_source_path']) {
                throw new Exception(
                    dt(
                        'Missing either "path" input or "!component_path" option for the !label component.',
                        [
                            '!component' => $component,
                            '!label' => $label,
                        ]
                    )
                );
            }
        }

        if ($options['destination_path']) {
            $this->destinationPath = $options['destination_path'];
            $this->autodetectDestination = false;

            if (!is_dir($this->destinationPath) && !mkdir($this->destinationPath)) {
                throw new Exception(dt('Failed creating destination directory "!destination"', ['!destination' => $this->destinationPath]));
            }
        }

        if ($options['code']) {
            $codeComponentPath = $options['code_source_path'] ?? Path::join($extractDir, self::COMPONENT_CODE);
            $this->importCode($codeComponentPath);
        }

        if ($options['files']) {
            $filesComponentPath = $options['files_source_path'] ?? Path::join($extractDir, self::COMPONENT_FILES);
            $this->importFiles($filesComponentPath, $options['files_destination_relative_path']);
        }

        if ($options['db']) {
            $databaseComponentPath = $options['db_source_path'] ?? Path::join($extractDir, self::COMPONENT_DATABASE, self::SQL_DUMP_FILE_NAME);
            $this->importDatabase($databaseComponentPath, $options);
        }

        $this->logger()->info(dt('Done!'));
    }

    /**
     * Creates a temporary directory to extract the archive onto.
     *
     * @throws \Exception
     */
    protected function prepareTempDir(): void
    {
        $this->filesystem = new Filesystem();
        $this->extractedPath = FsUtils::prepareBackupDir(self::TEMP_DIR_NAME);
        register_shutdown_function([$this, 'cleanUp']);
    }

    /**
     * Extracts the archive.
     *
     * @param string $path
     *   The path to the archive file.
     * @param array $options
     *   Command options.
     *
     * @return string
     *
     * @throws \Exception
     */
    protected function extractArchive(string $path, array $options): string
    {
        $this->logger()->info('Extracting the archive...');

        if (!is_file($path)) {
            throw new Exception(dt('File !path is not found.', ['!path' => $path]));
        }

        if (!preg_match('/\.tar\.gz$/', $path)) {
            throw new Exception(dt('File !path is not a *.tar.gz file.', ['!path' => $path]));
        }

        ['filename' => $archiveFileName] = pathinfo($path);
        $archiveFileName = str_replace('.tar', '', $archiveFileName);

        $extractDir = Path::join(dirname($path), $archiveFileName);
        if (is_dir($extractDir)) {
            if ($options['overwrite']) {
                $this->filesystem->remove($extractDir);
            } else {
                throw new Exception(
                    dt('Extract directory !path already exists (use "--overwrite" option).', ['!path' => $extractDir])
                );
            }
        }

        $this->filesystem->mkdir($extractDir);

        $archive = new PharData($path);
        $archive->extractTo($extractDir);

        $this->logger()->info(dt('The archive successfully extracted into !path', ['!path' => $extractDir]));

        return $extractDir;
    }

    /**
     * Imports the code to the site.
     *
     * @param string $source
     *   The path to the code files directory.
     *
     * @throws \Exception
     */
    protected function importCode(string $source): void
    {
        $this->logger()->info('Importing code...');

        if (!is_dir($source)) {
            throw new Exception(dt('Directory !path not found.', ['!path' => $source]));
        }

        $this->rsyncFiles($source, $this->getDestinationPath());
    }

    /**
     * Imports Drupal files to the site.
     *
     * @param string $source
     *   The path to the source directory.
     * @param null|string $destinationRelative
     *   The relative path to the Drupal files directory.
     *
     * @throws \Drush\Exceptions\UserAbortException
     * @throws \Exception
     */
    protected function importFiles(string $source, ?string $destinationRelative): void
    {
        $this->logger()->info('Importing files...');

        if (!is_dir($source)) {
            throw new Exception(dt('The source directory !path not found.', ['!path' => $source]));
        }

        if ($destinationRelative) {
            $destinationAbsolute = Path::join($this->getDestinationPath(), $destinationRelative);
            $this->rsyncFiles($source, $destinationAbsolute);

            return;
        }

        if ($this->autodetectDestination) {
            // @todo: catch error if possible
            Drush::bootstrapManager()->doBootstrap(DrupalBootLevels::FULL);
            $destinationAbsolute = Drupal::service('file_system')->realpath('public://');
            if (!$destinationAbsolute) {
                throw new Exception('Path to Drupal files is empty.');
            }

            $this->rsyncFiles($source, $destinationAbsolute);
            return;
        }

        throw new Exception(
            dt(
                'Can\'t detect relative path for Drupal files for destination "!destination": missing --files_destination_relative_path option.',
                ['!destination' => $this->getDestinationPath()]
            )
        );
    }

    /**
     * Returns the destination path.
     *
     * @return string
     */
    protected function getDestinationPath(): string
    {
        if (!$this->destinationPath) {
            $bootstrapManager = Drush::bootstrapManager();
            $this->destinationPath = $bootstrapManager->getComposerRoot();
        }

        return $this->destinationPath;
    }

    /**
     * Returns SiteAlias object by the site alias name.
     *
     * @param string|null $site
     *   The site alias.
     *
     * @return \Consolidation\SiteAlias\SiteAlias
     *
     * @throws \Exception
     */
    protected function getSiteAlias(?string $site): SiteAlias
    {
        $pathEvaluator = new BackendPathEvaluator();
        $manager = $this->siteAliasManager();

        if (null !== $site) {
            $site .= ':%root';
        }
        $evaluatedPath = HostPath::create($manager, $site);
        $pathEvaluator->evaluate($evaluatedPath);

        return $evaluatedPath->getSiteAlias();
    }

    /**
     * Copies files from the source to the destination.
     *
     * @param string $source
     *   The source path.
     * @param string $destination
     *   The destination path.
     *
     * @throws \Exception
     */
    protected function rsyncFiles(string $source, string $destination): void
    {
        $source = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $destination = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (
            !$this->io()->confirm(
                dt(
                    'Are you sure you want to sync files from "!source" to "!destination"?',
                    [
                        '!source' => $source,
                        '!destination' => $destination,
                    ]
                )
            )
        ) {
            throw new UserAbortException();
        }

        if (!is_dir($source)) {
            throw new Exception(dt('The source directory !path not found.', ['!path' => $source]));
        }
        if (!is_dir($destination)) {
            throw new Exception(dt('The destination directory !path not found.', ['!path' => $destination]));
        }

        $this->logger()->info(
            dt(
                'Copying files from "!source" to "!destination"...',
                [
                    '!source' => $source,
                    '!destination' => $destination,
                ]
            )
        );

        $options[] = '-akz';
        if ($this->output()->isVerbose()) {
            $options[] = '--stats';
            $options[] = '--progress';
            $options[] = '-v';
        }

        $command = sprintf(
            'rsync %s %s %s',
            implode(' ', $options),
            $source,
            $destination
        );

        /** @var \Consolidation\SiteProcess\ProcessBase $process */
        $process = $this->processManager()->shell($command);
        $process->run($process->showRealtime());
        if ($process->isSuccessful()) {
            return;
        }

        throw new Exception(
            dt(
                'Failed to copy files from !source to !destination: !error',
                [
                    '!source' => $source,
                    '!destination' => $destination,
                    '!error' => $process->getErrorOutput(),
                ]
            )
        );
    }

    /**
     * Imports the database dump to the site.
     *
     * @param string $databaseDumpPath
     *   The path to the database dump file.
     *
     * @throws \Drush\Exceptions\UserAbortException
     * @throws \Exception
     */
    protected function importDatabase(string $databaseDumpPath, array $options): void
    {
        $this->logger()->info('Importing database...');

        if (!is_file($databaseDumpPath)) {
            throw new Exception(dt('Database dump file !path not found.', ['!path' => $databaseDumpPath]));
        }

        // @todo: add support for database credentials.
        $bootstrapManager = Drush::bootstrapManager();
        $bootstrapManager->doBootstrap(DrupalBootLevels::CONFIGURATION);

        $sql = SqlBase::create($options);
        $databaseSpec = $sql->getDbSpec();

        if (
            !$this->io()->confirm(
                dt(
                    'Are you sure you want to drop the database "!database" (username: !user, prefix: !prefix, port: !port) and import the database dump "!path"?',
                    [
                        '!path' => $databaseDumpPath,
                        '!database' => $databaseSpec['database'],
                        '!prefix' => $databaseSpec['prefix'] ?: dt('n/a'),
                        '!user' => $databaseSpec['username'],
                        '!port' => $databaseSpec['port'],
                    ]
                )
            )
        ) {
            throw new UserAbortException();
        }

        if (!$sql->drop($sql->listTablesQuoted())) {
            throw new Exception(dt('Failed to drop the database.'));
        }

        if (!$sql->query('', $databaseDumpPath)) {
            throw new Exception(dt('Database import has failed.'));
        }
    }

    /**
     * Performs clean-up tasks.
     *
     * Deletes temporary directory.
     */
    public function cleanUp(): void
    {
        try {
            $this->logger()->info(dt('Deleting !path...', ['!path' => $this->extractedPath]));
            $this->filesystem->remove($this->extractedPath);
        } catch (IOException $e) {
            $this->logger()->info(
                dt(
                    'Failed deleting !path: !message',
                    ['!path' => $this->extractedPath, '!message' => $e->getMessage()]
                )
            );
        }
    }
}
