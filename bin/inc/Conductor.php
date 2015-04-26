<?php
require_once 'MysqlPdo.php';

class Conductor extends CliApplication
{

    /**
     * The main Conductor application version.
     */
    const CONDUCTOR_VERSION = "3.0.9";

    /**
     * The path to the core application configuration file.
     */
    const CONDUCTOR_CONF = "/etc/conductor.conf";

    /**
     * Number of spaces to use as indentation on the Nginx ENV block.
     */
    const SPACES_ENV_INDENT = 8;

    /**
     * The current application number.
     * @var string 
     */
    private $appname = '';

    /**
     * The current application base directory.
     * @var string 
     */
    private $appdir = '';

    /**
     * The conductor configuration object settings.
     * @var \stdClass
     */
    private $conf;

    /**
     * The MySQL PDO instance for database operations.
     * @var \Pdo
     */
    private $mysql;

    public function __construct($argv)
    {
        parent::__construct($argv);

        $this->checkDependencies();

        $this->enforceCli();

        if (!$this->isSuperUser()) {
            $this->writeln('You must be root to use this tool!');
            $this->endWithError();
        }

        $this->conf = $this->conductorConfiguration();

        $this->mysql = MysqlPdo::connect('information_schema', $this->conf->mysql->username, $this->conf->mysql->password, $this->conf->mysql->host);
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
            'PDO' => 'The PHP PDO extention is required but is missing',
            'pdo_mysql' => 'The PHP MySQL PDO extension is required but is missing',
            'posix' => 'The PHP POSIX extention is required but is missing',
            'json' => 'The PHP JSON extention is required but is missing',
        ];
        foreach ($depends as $function => $dependency) {
            if (!extension_loaded($function)) {
                $this->writeln($dependency);
                $this->endWithError();
            }
        }
    }

    /**
     * Requests that the request can only be executed if the user has specified the application name.
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
        if (in_array($action, ['start', 'stop', 'status', 'restart', 'reload'])) {
            $this->call($this->conf->services->nginx->$action);
            $this->call($this->conf->services->php_fpm->$action);
        } else {
            $this->writeln('The requested action could be found!');
            $this->endWithError();
        }
    }

    /**
     * Reloadsof the Nginx configuration for environment variables to take affect.
     */
    public function reloadEnvVars()
    {
        $this->call($this->conf->services->nginx->reload);
    }

    /**
     * Detects the current Laravel application version (if not found will return empty)
     * @param string $application The application of which to check the version of.
     * @return string
     */
    private function laravelApplicationVersion($application)
    {
        if (file_exists($this->conf->paths->apps . '/' . $application . '/artisan')) {
            ob_start();
            $this->call($this->conf->binaries->php . ' ' . $this->conf->paths->apps . '/' . $application . '/artisan --version');
            $data = ob_get_clean();
            if ((strpos($data, 'version') !== false) and ( preg_match("#(\d+\.\d+(\.\d+)*)#", $data, $version_number))) {
                if (isset($version_number[0])) {
                    return $version_number[0];
                }
                return "";
            }
            return "";
        }
        return "";
    }

    /**
     * Executes a backup the entire web application including it's database.
     * @param string $filename The filename to use when creating the backup.
     * @return void
     */
    private function backupApplication($filename)
    {
        $this->appNameRequired();
        $this->call('cp -R ' . $this->appdir . ' ' . $this->conf->paths->temp . '/' . $this->appname);
        if ($this->mysql->query('SHOW DATABASES LIKE \'db_' . $this->appname . '\';')->fetchObject()) {
            $this->writeln('Detected a MySQL database, backing it up...');
            $this->call($this->conf->binaries->mysqldump . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' --no-create-db db_' . $this->appname . ' | ' . $this->conf->binaries->gzip . ' -c | cat > ' . $this->conf->paths->temp . '/' . $this->appname . '/appdb.sql.gz');
        }
        $this->writeln('Compressing backup archive...');
        $this->call('tar -zcf ' . $this->conf->paths->temp . '/' . $filename . ' -C ' . $this->conf->paths->temp . '/' . $this->appname . '/ .');
        $this->writeln('Cleaning up...');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->call('mv ' . $this->conf->paths->temp . '/' . $filename . ' ' . $this->conf->paths->backups . '/' . $filename);
    }

    /**
     * Creates a new MySQL user and database.
     * @param string $db_pass The password of which to use for the user account.
     * @return void
     */
    private function createMySQL($db_pass)
    {
        $this->appNameRequired();

        $this->mysql->exec('CREATE DATABASE IF NOT EXISTS `db_' . $this->appname . '`;');
        $this->mysql->exec('GRANT ALL ON `db_' . $this->appname . '`.* TO \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\' IDENTIFIED BY \'' . $db_pass . '\';');
        $this->mysql->exec('FLUSH PRIVILEGES;');

        $this->writeln();
        $this->writeln('MySQL Database and User Details:');
        $this->writeln();
        $this->writeln(' DB Name: db_' . $this->appname);
        $this->writeln(' DB Host: ' . $this->conf->mysql->host);
        $this->writeln(' DB Username: ' . $this->appname);
        $this->writeln(' DB Password: ' . $db_pass);
        $this->writeln();

        // For convienice we'll add these DB params to the ENV vars with the benefit of using default Laravel ENV var names .
        $this->call('/usr/bin/conductor envars ' . $this->appname . ' --DB_HOST="' . $this->conf->mysql->host . '" --DB_DATABASE="db_' . $this->appname . '" --DB_USERNAME="' . $this->appname . '"  --DB_PASSWORD="' . $db_pass . '"');
    }

    /**
     * Destroys the database and user for the current application.
     * @return void
     */
    private function destroyMySQL()
    {
        $this->appNameRequired();
        $this->mysql->exec('DROP DATABASE IF EXISTS `db_' . $this->appname . '`;');
        $this->mysql->exec('DROP USER \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\';');
        $this->mysql->exec('FLUSH PRIVILEGES;');
    }

    /**
     * If detected as a Laravel appliaction will attempt to migrate it based on it's framework version number.
     * @param string $environment The environment of which to execute the Laravel commands under.
     * @return void
     */
    private function migrateLaravel($environment = 'production')
    {
        if (file_exists($this->appdir . '/artisan')) {
            if (version_compare($this->laravelApplicationVersion($this->appname), "4.2", ">=")) {
                $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan migrate --force --env=' . $environment);
            } else {
                $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan migrate --env=' . $environment);
            }
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan cache:clear --env=' . $environment);
            $this->call($this->conf->binaries->composer . ' dump-autoload -o --working-dir=' . $this->appdir);
        }
    }

    /**
     * Pull the latest version of the application from Git and reset the local copy as appropriate.
     * @return void
     */
    private function gitPull()
    {
        $this->call($this->conf->binaries->git . ' --git-dir=' . $this->appdir . '/.git --work-tree=' . $this->appdir . ' fetch --all');
        $this->call($this->conf->binaries->git . ' --git-dir=' . $this->appdir . '/.git --work-tree=' . $this->appdir . ' reset --hard origin/master');
    }

    /**
     * Update the environmental configuration for the application.
     * @return void
     */
    public function updateEnvVars()
    {
        $this->appNameRequired();
        if (!file_exists($this->conf->paths->apps . '/' . $this->appname)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }
        $env_conf = $this->conf->paths->appconfs . '/' . $this->appname . '_envars.json';
        if (!file_exists($env_conf)) {
            file_put_contents($env_conf, json_encode(['APP_ENV' => 'production']));
            $this->reloadEnvVars();
        }

        $env_handler = new EnvHandler($env_conf);
        $env_handler->load();

        if ((strtolower($this->getCommand(1)) == 'envars') and ( count($this->options()) > 0)) {
            if (!$this->isFlagSet('d')) {
                foreach ($this->options() as $key => $value) {
                    $env_handler->push($key, $value);
                }
            } else {
                foreach ($this->options() as $key => $value) {
                    $env_handler->remove($key);
                }
            }
            // Lets now write the changes to the file...
            $env_handler->save();

            // Apply them to the application configuration...
            $ammended_vhost_conf = $this->replaceBetweenSections('# START APPLICTION ENV VARIABLES', '# END APPLICTION ENV VARIABLES', file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf'), $this->envConfigurationBlock($env_handler));
            file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $ammended_vhost_conf);
            file_put_contents($this->conf->paths->apps . '/' . $this->appname . '/.env', $this->envFileLaravelConfiguration($env_handler));
            $this->reloadEnvVars();
        } else {
            $this->writeln();
            foreach ($env_handler->all() as $key => $value) {
                $this->writeln(sprintf(' %s=%s', $key, $value));
            }
            $this->writeln();
        }
    }

    /**
     * Format the environment variables for the virtual host configuration.
     * @param EnvHandler $envars The environmental variables.
     * @return string
     */
    private function envConfigurationBlock(EnvHandler $envars)
    {
        $block = PHP_EOL . "";
        if (count($envars->all()) > 0) {
            foreach ($envars->all() as $key => $value) {
                $block .= sprintf(str_repeat(' ', self::SPACES_ENV_INDENT) . "fastcgi_param    %s    %s;" . PHP_EOL, $key, $value);
            }
        }
        return $block;
    }

    /**
     * Formats the environment variables for Laravel 5 based applications.
     * @param EnvHandler $envars The environmental variables.
     * @return string
     */
    private function envFileLaravelConfiguration(EnvHandler $envars)
    {
        $block = "";
        if (count($envars->all()) > 0) {
            foreach ($envars->all() as $key => $value) {
                $block.= $key . "=" . $value . PHP_EOL;
            }
        }
        return $block;
    }

    /**
     * Replaces the text/content between two points.
     * @param string $needle_start
     * @param string $needle_end
     * @param string $file
     * @param string $replacement
     * @return string
     */
    public function replaceBetweenSections($needle_start, $needle_end, $file, $replacement)
    {
        $pos = strpos($file, $needle_start);
        $start = $pos === false ? 0 : $pos + strlen($needle_start);
        $pos = strpos($file, $needle_end, $start);
        $end = $pos === false ? strlen($file) : $pos;
        return substr_replace($file, $replacement, $start, $end - $start);
    }

    /**
     * Adds the Laravel 5 application task scheduler to the servers cron-tab and enables it.
     * @return void
     */
    private function addScheduler()
    {
        $this->appNameRequired();
        $cron_conf_path = $this->conf->paths->crons . '/laravel_' . $this->appname;
        if (!file_exists($cron_conf_path)) {
            // Add file
            file_put_contents($cron_conf_path, sprintf('* * * * * %s %s %s/artisan schedule:run 1>> /dev/null 2>&1', $this->conf->permissions->webuser, $this->conf->binaries->php, $this->appdir));
            // Chmod file(s)
            chmod($cron_conf_path, 755);
            // Reload Crons
            $this->reloadCronJobs();
            $this->writeln('Added Laravel 5 task scheduler cron to the system.');
        } else {
            $this->writeln('Task scheduler cron already exists.');
        }
    }

    /**
     * Removes the Laravel 5 application task scheduler from the servers cron-tab.
     */
    private function removeScheduler()
    {
        $this->appNameRequired();
        $cron_conf_path = $this->conf->paths->crons . '/laravel_' . $this->appname;
        if (file_exists($cron_conf_path)) {
            unlink($cron_conf_path);
            $this->writeln('Successfully deleted Laravel Scheduler from the system cron!');
        } else {
            $this->writeln('No Task scheduler cron found, skipping...');
        }
    }

    /**
     * Reloads the Crontab service
     * return @void
     */
    private function reloadCronJobs()
    {
        $this->call($this->conf->services->cron->reload);
        $this->writeln('Reloaded the system crons.');
    }

    /**
     * Creates a new application hosting container (and deploys as required)
     */
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

        if (!$this->getOption('fqdn')) {
            // Entering interactive mode...
            $domain = $this->input('Domains (FQDN\'s) to map this application to:');
            $apppath = $this->input('Hosted directory:', '/public');
            $environment = $this->input('Environment type:', 'production');
            $mysql_req = $this->input('Requires MySQL?', 'y', ['y', 'n']);
            $deploy_git = $this->input('Deploy application with Git now?', 'y', ['y', 'n']);
        } else {
            // FQDN is set, entering non-interactive mode!
            $domain = $this->getOption('fqdn');
            $environment = $this->getOption('environment', 'production');
            $apppath = $this->getOption('path', '/public');

            if ($this->getOption('mysql-pass')) {
                $mysql_req = 'y';
                $password = $this->getOption('mysql-pass');
            } else {
                $mysql_req = 'n';
            }

            if ($this->getOption('git-uri')) {
                $deploy_git = 'y';
                $gitrepo = $this->getOption('git-uri');
            } else {
                $deploy_git = 'n';
            }
        }

        // Trim any trailing slash from the $path variable...
        $apppath = rtrim($apppath, '/');

        if (strtolower($deploy_git) == 'y') {
            if (!isset($gitrepo)) {
                $this->writeln();
                $gitrepo = $this->input('Git repository URL:');
                $this->writeln();
            }
        }

        // Copy the virtualhost configuration file to our application configuration directory.
        copy($this->conf->paths->templates . '/vhost_template.tpl', $this->conf->paths->appconfs . '/' . $this->appname . '.conf');

        $placeholders = [
            '@@DOMAIN@@' => $domain,
            '@@APPNAME@@' => $this->appname,
            '@@APPPATH@@' => $this->appname . $apppath,
            '@@HLOGS@@' => $this->conf->paths->applogs . '/' . $this->appname . '/',
            '@@ENVIROMENT@@' => $environment,
            '@@SOCKET@@' => $this->conf->paths->fpmsocket,
            '@@FASTCGIPARAMS@@' => $this->conf->paths->fastcgiparams,
        ];
        $config = file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf');
        foreach ($placeholders as $placeholder => $value) {
            $config = str_replace($placeholder, $value, $config);
        }
        file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $config);

        mkdir($this->appdir, 0755);
        mkdir($this->conf->paths->applogs . '/' . $this->appname, 0755);
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->conf->paths->applogs . '/' . $this->appname);
        chmod($this->conf->paths->appconfs . '/' . $this->appname . '.conf', 755);

        // Load  the application environment configuration in to the application configuration (which will create the initial ENV configuration)...
        //$this->updateEnvVars();
        $this->call('/usr/bin/conductor envars APP_ENV="' . $environment . '"');

        // Enable the site by reloading Nginx.
        //$this->call($this->conf->services->nginx->reload);

        if (strtolower($deploy_git) == 'y') {
            $this->writeln('We\'ll now deploy your application using Git...');
            $this->call('rm -Rf ' . $this->appname);
            $this->call($this->conf->binaries->git . ' clone ' . $gitrepo . ' ' . $this->appdir);
            if (file_exists($this->appdir . '/vendor')) {
                $this->writeln('Skipping dependencies are the \'vendor\' directory exists!');
            } else {
                $this->writeln('Downloading dependencies...');
                $this->call($this->conf->binaries->composer . ' install --no-dev --optimize-autoloader --working-dir=' . $this->appdir);
            }
        } else {
            $this->writeln('To deploy your application, manually copy the files to:');
            $this->writeln();
            $this->writeln($this->appdir . '/');
            $this->writeln();
            $this->writeln('Alternatively if you are migrating from another server, use \'conductor restore ' . $this->appname . '\' to restore now!');
        }

        $this->writeln('Setting ownership permissions on application files...');
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);

        if (strtolower($mysql_req) == 'y') {
            $this->writeln();
            if (!isset($password)) {
                $password = $this->input('Please enter a password for the MySQL database:');
            }
            $this->createMySQL($password);
        }

        $this->migrateLaravel($environment);
    }

    /**
     * Updates the code and executes migrations on an exsisting database.
     */
    public function updateApplication()
    {
        $this->appNameRequired();

        // Get the current environment type to execute the Laravel migrations with.
        $env_handler = new EnvHandler($this->conf->paths->appconfs . '/' . $this->appname . '_envars.json');
        $env_handler->load();
        $environment = $env_handler->get('APP_ENV', 'production');

        // Checks for CLI options to surpress the 'stop' application user input.
        if ($this->getOption('down', false)) {
            if ($this->getOption('down') == "true") {
                $stopapp = 'y';
            } else {
                $stopapp = 'n';
            }
        }

        if (!file_exists($this->appdir)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }

        if (!isset($stopapp)) {
            $stopapp = $this->input('Do you wish to \'stop\' the application before upgrading?', 'y', ['y', 'n']);
        }

        if (strtolower($stopapp) == 'y') {
            $this->stopLaravelApplication();
        }

        if (file_exists($this->conf->paths->backups . '/rollback_' . $this->appname . '.tag.gz')) {
            unlink($this->conf->paths->backups . '/rollback_' . $this->appname . '.tag.gz');
        }
        $this->backupApplication('rollback_' . $this->appname . '.tar.gz');
        $this->writeln('Starting application upgrade...');
        if (file_exists($this->appdir . '/.git')) {
            $this->writeln('Pulling latest code from Git...');
            $this->gitPull();
            $this->writeln('Downloading Composer dependencies...');
            $this->call($this->conf->binaries->composer . ' install --no-dev --optimize-autoloader --working-dir=' . $this->appdir);
        }
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        $this->migrateLaravel($environment);
        $this->writeln('...finished!');
        if (strtolower($stopapp) == 'y') {
            $this->startLaravelApplication();
        }

        $this->endWithSuccess();
    }

    /**
     * List all the hosted applications on the server.
     */
    public function listApplications()
    {
        $applications = new DirectoryIterator($this->conf->paths->apps);
        $this->writeln();
        foreach ($applications as $application) {
            $lav = "";
            if ($application->isDir() and ( $application->getBasename()[0] != '.')) {
                $laravel_framework_version = $this->laravelApplicationVersion($application->getBasename());
                if (!empty($laravel_framework_version)) {
                    $lav = ' [Laravel v' . $laravel_framework_version . ']';
                }
                $this->writeln(' - ' . $application->getBasename() . $lav);
            }
        }
        $this->writeln();
    }

    /**
     * Initiates an application backup.
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
        $this->writeln('Tell us which archive you wish to restore (eg. /var/conductor/backups/myapp_2013-10-26-0900.tar.gz)');
        $archive = $this->input('Backup archive:');

        if (!file_exists($archive)) {
            $this->writeln('The backup archive could not be found!');
            $this->endWithError();
        }

        mkdir($this->conf->paths->temp . '/restore_' . $this->appname, 755);
        $this->call('tar -zxf ' . $archive . ' -C ' . $this->conf->paths->temp . '/restore_' . $this->appname);

        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            unlink($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $this->call('rm -Rf ' . $this->appdir);
        $this->call('cp -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/ ' . $this->appdir . '/');
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname);
        $this->writeln('...finished!');
        $this->startLaravelApplication();
        $this->endWithSuccess();
    }

    /**
     * Rollback the current applicaiton to it's state prior to the upgrade.
     */
    public function rollback()
    {
        $this->appNameRequired();
        if (!file_exists($this->conf->paths->backups . '/rollback_' . $this->appname . '.tar.gz')) {
            $this->writeln('There is no available rollback snapshot to restore to!');
            $this->endWithError();
        }

        mkdir($this->conf->paths->temp . '/rollback_' . $this->appname, 755);
        $this->writeln('Extracting the rollback image...');
        $this->call('tar -zxf ' . $this->conf->paths->backups . '/rollback_' . $this->appname . '.tar.gz -C ' . $this->conf->paths->temp . '/rollback_' . $this->appname);

        if (file_exists($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            unlink($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $this->call('rm -Rf ' . $this->appdir);
        $this->call('cp -Rf ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/ ' . $this->appdir . '/');
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/rollback_' . $this->appname);
        $this->writeln('...finished!');
        $this->writeln('Forcing application start...');
        $this->startLaravelApplication();
        $this->endWithSuccess();
    }

    /**
     * Delete an application and associated data.
     */
    public function destroy()
    {
        $this->appNameRequired();
        if (file_exists($this->appdir)) {
            $this->writeln('Running a quick snapshot as you can never be too careful...');
            $this->backupApplication('priordestroy_' . $this->appname . '.tar.gz');
            $this->writeln('Destroying application...');
            $this->call('rm ' . $this->conf->paths->appconfs . '/' . $this->appname . '*');
            $this->writeln('Reloading Nginx configuration...');
            $this->call($this->conf->services->nginx->reload);
            $this->writeln('Destroying MySQL database and associated users...');
            $this->destroyMySQL();
            $this->writeln('Destroying app directory and log files...');
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
     * Start a specific Laravel application.
     */
    public function startLaravelApplication()
    {
        $this->appNameRequired();
        $this->writeln('Attempting to start the Laravel Application');
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan up');
        } else {
            $this->writeln('Could not find the \'artisan\' tool!');
            $this->endWithError();
        }
    }

    /**
     * Stop a specific Laravel application.
     * @return void
     */
    public function stopLaravelApplication()
    {
        $this->appNameRequired();
        $this->writeln('Attempting to stop the Laravel Application');
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan down');
        } else {
            $this->writeln('Could not find the \'artisan\' tool!');
            $this->endWithError();
        }
    }
}
