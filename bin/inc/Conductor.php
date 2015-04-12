<?php

class Conductor extends CliApplication
{

    const CONDUCTOR_VERSION = "3.0 dev";
    const CONDUCTOR_CONF = "/Users/ballen/Desktop/conductor.json";

    /**
     * The current application number.
     * @var string 
     */
    private $appname = '';

    /**
     * The current application base directory.
     * @var type 
     */
    private $appdir = '';

    /**
     * The conductor configuration object settings.
     * @var \stdClass
     */
    private $conf;

    public function __construct($argv, $appname = '')
    {
        parent::__construct($argv);

        $this->enforceCli();

        if (!$this->isSuperUser()) {
            $this->writeln('You must be root to use this tool!');
            $this->endWithError();
        }

        $this->conf = $this->conductorConfiguration();
        $this->appname = $appname;
        $this->appdir = $this->conf->paths->apps . '/' . $appname;
    }

    /**
     * Loads the Conductor configuration file.
     * @return stdClass
     */
    private function conductorConfiguration()
    {
        if (file_exists(self::CONDUCTOR_CONF)) {
            return json_decode(file_get_contents(self::CONDUCTOR_CONF));
        } else {
            $this->writeln('The conductor configuration file could not be found!');
            $this->endWithError();
        }
    }

    /**
     * Returns the current version of Conductor.
     * @return string
     */
    public function version()
    {
        return self::CONDUCTOR_VERSION;
    }

    /**
     * Checks the Conductor CLI dependencies and will exit if not fully satistifed.
     * @return void
     */
    public function checkDependencies()
    {
        $depends = [
            'mysql' => 'The PHP MySQL extention is required but is missing',
        ];
        foreach ($depends as $function => $dependency) {
            if (!function_exists($function)) {
                $this->writeln($dependency);
                $this->endWithError();
            }
        }
    }

    /**
     * Checks and exit's if an application is not specified!
     * @return void
     */
    private function appNameRequired()
    {
        if (empty($this->appname)) {
            $this->writeln('No application name was specified!');
            $this->endWithError();
        }
    }

    /**
     * Action a Conductor service action.
     * @param string $action
     */
    public function serviceControl($action)
    {
        if (in_array($action, ['start', 'stop', 'restart', 'reload'])) {
            $this->call($this->conf->services->nginx->$action);
            $this->call($this->conf->services->php_fpm->$action);
        } else {
            $this->writeln('The requested action could be found!');
            $this->endWithError();
        }
    }

    /**
     * Backs up the entire web application including it's database.
     * @param string $filename The filename to use when creating the backup.
     * @return void
     */
    private function backupApplication($filename)
    {
        $this->appNameRequired();
        $this->call('cp -R ' . $this->appdir . ' ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->writeln('Backing up MySQL database (if exists)...');
        $this->call($this->conf->binaries->mysqldump . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' --no-create-db db_' . $this->appname . ' | ' . $this->conf->binaries->gzip . ' -c | cat > ' . $this->conf->paths->temp . '/' . $this->appname . '/appdb.sql.gz');
        $this->writeln('Compressing backup archive...');
        $this->call('tar -zcf ' . $this->conf->paths->temp . '/' . $this->appname . ' -C ' . $this->conf->paths->temp . '/' . $this->appname . '/ .');
        $this->writeln('Cleaning up...');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/' . $this->appname . '.tar.gz');
        $this->call('mv ' . $this->conf->paths->temp . '/' . $this->appname . '.tar.gz ' . $this->conf->paths->backups . '/' . $filename);
    }

    /**
     * Creates a new MySQL user and database.
     * @param strin $db_pass The password of which to use for the user account.
     * @return void
     */
    private function createMySQL($db_pass)
    {
        $this->appNameRequired();
        $db = mysql_connect($this->conf->mysql->host, $this->conf->mysql->username, $this->conf->mysql->password);
        if (!$db) {
            $this->writeln('MySQL connection error: ' . mysql_error());
        }
        mysql_query('CREATE DATABASE IF NOT EXISTS `db_' . $this->appname . '`;', $db);
        mysql_query('GRANT ALL ON `db_' . $this->appname . '`.* TO \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\' IDENTIFIED BY \'' . $db_pass . '\';', $db);
        mysql_query('FLUSH PRIVILEGES;', $db);
        mysql_close($db);

        $this->writeln();
        $this->writeln('MySQL Database and User Details:');
        $this->writeln();
        $this->writeln('  DB Name: db_' . $this->appname);
        $this->writeln('  DB Host: ' . $this->conf->mysql->host);
        $this->writeln('  DB Username: ' . $this->conf->mysql->username);
        $this->writeln('  DB Password: ' . $this->conf->mysql->password);
        $this->writeln();
    }

    private function destroyMySQL()
    {
        $this->appNameRequired();
        $db = mysql_connect($this->conf->mysql->host, $this->conf->mysql->username, $this->conf->mysql->password);
        if (!$db) {
            $this->writeln('MySQL connection error: ' . mysql_error());
        }
        mysql_query('DROP DATABASE IF EXISTS `db_' . $this->appname . '`;', $db);
        mysql_query('DROP USER \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\';', $db);
        mysql_query('FLUSH PRIVILEGES;', $db);
        mysql_close($db);
    }

    /**
     * Migrate a Laravel specific application.
     * @return void
     */
    private function migrateLaravel()
    {
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan --force');
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan cache:clear');
            $this->call($this->conf->binaries->composer . ' dump-autoload -o --working-dir = ' . $this->appdir);
        }
    }

    /**
     * Pull the latest version from Git and reset as appropriate
     * @return void
     */
    private function gitPull()
    {
        $this->call($this->conf->binaries->git . ' --git-dir = ' . $this->appdir . '/.git --work-tree = ' . $this->appdir . ' fetch --all');
        $this->call($this->conf->binaries->git . ' --git-dir = ' . $this->appdir . '/.git --work-tree = ' . $this->appdir . ' reset --hard origin/master');
    }

    /**
     * List the applications on the server
     */
    public function listApplications()
    {
        $applications = new DirectoryIterator($this->appdir);
        $this->writeln();
        foreach ($applications as $application) {
            if ($application->isDir() and ( $application->getBasename()[0] != '.')) {
                $this->writeln(' - ' . $application->getBasename());
            }
        }
        $this->writeln();
    }

    /**
     * Initiates an application backup.
     * @return void
     */
    public function backup()
    {
        $archive_filename = $this->appname . '-' . date('Y-m-d-H-i') . '.tar.gz';
        if (file_exists($this->appdir)) {
            $this->writeln('Starting application backup...');
            $this->backupApplication($archive_filename);
            $this->writeln('...finished!');
            $this->writeln();
            $this->writeln('Backup successfully created: ' . $this->conf->paths->backups . '/' . $this->appname . '.tar.gz');
            $this->writeln();
            $this->endWithSuccess();
        } else {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
    }

    /**
     * Destroy an application
     * @return void
     */
    public function destroy()
    {

        if (file_exists($this->appdir)) {
            $this->writeln('Running a quick snapshot as you can never be too careful...');
            $this->backup('priordestroy_' . $this->appname . '.tar.gz');
            $this->writeln('Destroying application...');
            $this->call('rm ' . $this->conf->paths->appconfs . '/' . $this->appname . '.conf');
            $this->call($this->conf->services->nginx->reload);

            if (file_exists($$this->appdir . '/conductor.json')) {
                $this->destroyMySQL();
            }
            $this->call('rm -Rf ' . $this->appdir);
            $this->call('rm -Rf ' . $this->applogs . $this->appname);
            $this->writeln('...finished!');
            $this->endWithSuccess();
        } else {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
    }

    /**
     * Start a Laravel application.
     * @return void
     */
    public function startLaravelApplication()
    {
        $this->writeln('Attempting to start the Laravel Application');
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->appdir . '/artisan start');
        }
        $this->writeln('Could not find the \'artisan\' tool!');
    }

    /**
     * Stop a Laravel application.
     * @return void
     */
    public function stopLaravelApplication()
    {
        $this->writeln('Attempting to stop the Laravel Application');
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->appdir . '/artisan stop');
        }
        $this->writeln('Could not find the \'artisan\' tool!');
    }
}
