<?php

class Conductor extends CliApplication
{

    const CONDUCTOR_VERSION = "3.0 dev";
    const CONDUCTOR_CONF = "/etc/conductor.conf";

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

    public function __construct($argv)
    {
        parent::__construct($argv);

        $this->enforceCli();

        if (!$this->isSuperUser()) {
            $this->writeln('You must be root to use this tool!');
            $this->endWithError();
        }

        $this->conf = $this->conductorConfiguration();
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
        $this->setAppName();
    }

    /**
     * Sets the application name to the second param
     * @return void
     */
    private function setAppName()
    {
        if (!$this->getCommand(2)) {
            $this->writeln('No application name was specified!');
            $this->endWithError();
        }
        $this->appname = $this->getCommand(2);
        $this->appdir = $this->conf->paths->apps . '/' . $this->appname;
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
        $this->call('tar -zcf ' . $this->conf->paths->temp . '/' . $filename . ' -C ' . $this->conf->paths->temp . '/' . $this->appname . '/ .');
        $this->writeln('Cleaning up...');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->call('mv ' . $this->conf->paths->temp . '/' . $filename . ' ' . $this->conf->paths->backups . '/' . $filename);
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
        $this->writeln('  DB Username: ' . $this->appname);
        $this->writeln('  DB Password: ' . $db_pass);
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
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan migrate --force');
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan cache:clear');
            $this->call($this->conf->binaries->composer . ' dump-autoload -o --working-dir=' . $this->appdir);
        }
    }

    /**
     * Pull the latest version from Git and reset as appropriate
     * @return void
     */
    private function gitPull()
    {
        $this->call($this->conf->binaries->git . ' --git-dir=' . $this->appdir . '/.git --work-tree=' . $this->appdir . ' fetch --all');
        $this->call($this->conf->binaries->git . ' --git-dir=' . $this->appdir . '/.git --work-tree=' . $this->appdir . ' reset --hard origin/master');
    }

    public function newApplication()
    {
        $this->appNameRequired();
        if (file_exists($this->appdir)) {
            $this->writeln('Cannot create new application as it already exists on this server!');
            $this->endWithError();
        }

        if (strlen($this->appname) > 14) {
            $this->writeln('Application name cannot exceed 14 characters!');
            $this->endWithError();
        }

        $domain = $this->input('Domains (FQDN\'s) to map this application to:');
        $environment = $this->input('Environment type:', 'production');
        $mysql_req = $this->input('Requires MySQL?', 'y', ['y', 'n']);
        $deploy_git = $this->input('Deploy application with Git now?', 'y', ['y', 'n']);

        if (strtolower($deploy_git) == 'y') {
            $this->writeln();
            $gitrepo = $this->input('Git repository URL:');
            $this->writeln();
        }

        // Validate that the Domain/Domains are valid FQDN's

        copy($this->conf->paths->templates . '/laravel_template.tpl', $this->conf->paths->appconfs . '/' . $this->appname . '.conf');

        $placeholders = [
            '@@DOMAIN@@' => $domain,
            '@@APPNAME@@' => $this->appname,
            '@@HLOGS@@' => $this->conf->paths->applogs . '/' . $this->appname . '/',
            '@@ENVIROMENT@@' => $environment,
        ];
        $config = file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf');
        foreach ($placeholders as $placeholder => $value) {
            $config = str_replace($placeholder, $value, $config);
        }
        file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $config);


        mkdir($this->appdir, 0755);
        mkdir($this->conf->paths->applogs . '/' . $this->appname);
        $this->call('chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->conf->paths->applogs . '/' . $this->appname . ' -R');
        chmod($this->conf->paths->appconfs . '/' . $this->appname . '.conf', 755);

        $this->call($this->conf->services->nginx->reload);

        if (strtolower($deploy_git) == 'y') {
            $this->writeln('We\'ll now deploy your application using Git...');
            $this->call('rm -Rf ' . $this->appname);
            $this->call($this->conf->binaries->git . ' clone ' . $gitrepo . ' ' . $this->appdir);
            if (file_exists($this->appdir . '/vendor')) {
                $this->writeln('Skipping dependencies are the \'vendor\' directory exists!');
            } else {
                $this->writeln('Downloading dependencies...');
                $this->call($this->conf->binaries->composer . ' install --no-dev --optimize-autoloader --working-dir=' . $this->appdir . '');
            }
        } else {
            $this->writeln('To deploy your application, manually copy the files to:');
            $this->writeln();
            $this->writeln($this->appdir . '/');
            $this->writeln();
            $this->writeln('Alternatively if you are migrating from another server, use \'conductor restore ' . $this->appname . '\' to restore now!');
        }

        $this->writeln('Setting ownership permissions on application files...');
        $this->call('chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir . ' -R');

        if (strtolower($mysql_req) == 'y') {
            $this->writeln();
            $password = $this->input('Please enter a password for the MySQL database:');
            $this->createMySQL($password);
        }

        $this->migrateLaravel();
    }

    public function updateApplication()
    {
        $this->appNameRequired();

        if (!file_exists($this->appdir)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }

        $stopapp = $this->input('Do you wish to \'stop\' the application before upgrading?', 'y', ['y', 'n']);
        if ($stopapp == 'y')
            $this->stopLaravelApplication();

        if (file_exists($this->conf->paths->backups . '/rollback_' . $this->appname . '.tag.gz')) {
            unlink($this->conf->paths->backups . '/rollback_' . $this->appname . '.tag.gz');
        }
        $this->backupApplication('rollback_' . $this->appname . '.tar.gz');
        $this->writeln('Starting application upgrade...');
        if (file_exists($this->appdir . '/.git')) {
            $this->gitPull();
        }
        $this->call('chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir . ' -R');
        $this->migrateLaravel();
        $this->writeln('...finished!');
        if ($stopapp == 'y')
            $this->startLaravelApplication();

        $this->endWithSuccess();
    }

    /**
     * List the applications on the server
     */
    public function listApplications()
    {
        $applications = new DirectoryIterator($this->conf->paths->apps);
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
        $this->appNameRequired();
        $archive_filename = $this->appname . '-' . date('Y-m-d-H-i') . '.tar.gz';
        if (file_exists($this->appdir)) {
            $this->writeln('Starting application backup...');
            $this->backupApplication($archive_filename);
            $this->writeln('...finished!');
            $this->writeln();
            $this->writeln('Backup successfully created: ' . $this->conf->paths->backups . '/' . $archive_filename);
            $this->writeln();
            $this->endWithSuccess();
        } else {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
    }

    /**
     * Restores an application from a backup.
     */
    public function restore()
    {
        $this->appNameRequired();
        $this->writeln('Tell us which archive you wish to restore (eg. /var/conductor/backups/myapp_2013-10-26-0900.tar.gz/)');
        $archive = $this->input('Backup archive:');

        if (!file_exists($archive)) {
            $this->writeln('The backup archive could not be found!');
            $this->endWithError();
        }

        mkdir($this->conf->paths->temp . '/restore_' . $this->appname, 755);
        $this->call('tar -zxf ' . $archive . ' -C ' . $this->conf->paths->temp . '/restore_' . $this->appname);

        if (file_exists($this->conf->paths->temp . 'restore_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz\' | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            unlink($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $this->call('rm -Rf ' . $this->appdir);
        $this->call('cp -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/ ' . $this->appdir . '/');
        $this->call('chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir . ' -R');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname);

        $this->writeln('...finished!');
        $this->endWithSuccess();
    }

    public function rollback()
    {
        $this->appNameRequired();
        if (!file_exist($this->conf->paths->backups . '/rollback_' . $this->appname . '.tar.gz')) {
            $this->writeln('There is no available rollback snapshot to restore to.');
            $this->endWithError();
        }

        mkdir($this->conf->paths->temp . '/rollback_' . $this->appname, 755);
        $this->call('tar -zxf ' . $this->conf->paths->backups . '/rollback_' . $this->appname . '.tar.gz -C ' . $this->conf->paths->temp . 'rollback_' . $this->appname);

        if (file_exists($this->conf->paths->temp . 'rollback_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz\' | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            unlink($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $this->call('rm -Rf ' . $this->appdir);
        $this->call('cp -Rf ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/ ' . $this->appdir . '/');
        $this->call('chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir . ' -R');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/rollback_' . $this->appname);

        $this->writeln('...finished!');
        $this->endWithSuccess();
    }

    /**
     * Destroy an application
     * @return void
     */
    public function destroy()
    {
        $this->appNameRequired();
        if (file_exists($this->appdir)) {
            $this->writeln('Running a quick snapshot as you can never be too careful...');
            $this->backupApplication('priordestroy_' . $this->appname . '.tar.gz');
            $this->writeln('Destroying application...');
            $this->call('rm ' . $this->conf->paths->appconfs . '/' . $this->appname . '.conf');
            $this->call($this->conf->services->nginx->reload);

            if (file_exists($this->appdir . '/conductor.json')) {
                $this->destroyMySQL();
            }
            $this->call('rm -Rf ' . $this->appdir);
            $this->call('rm -Rf ' . $this->conf->paths->applogs . '/' . $this->appname);
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
        $this->appNameRequired();
        $this->writeln('Attempting to start the Laravel Application');
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan up');
            $this->endWithSuccess();
        }
        $this->writeln('Could not find the \'artisan\' tool!');
        $this->endWithError();
    }

    /**
     * Stop a Laravel application.
     * @return void
     */
    public function stopLaravelApplication()
    {
        $this->appNameRequired();
        $this->writeln('Attempting to stop the Laravel Application');
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan down');
            $this->endWithSuccess();
        }
        $this->writeln('Could not find the \'artisan\' tool!');
        $this->endWithError();
    }
}
