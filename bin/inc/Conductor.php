<?php

require_once 'MysqlPdo.php';

/**
 * Conductor
 *
 * Conductor is a CLI tool to aid provisioning and maintenance of PHP based sites and applications.
 *
 * @author Bobby Allen <ballen@bobbyallen.me>
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/allebb/conductor
 * @link http://bobbyallen.me
 *
 */
class Conductor extends CliApplication
{

    /**
     * The main Conductor application version.
     */
    const CONDUCTOR_VERSION = "3.2.0";

    /**
     * The path to the core application configuration file.
     */
    const CONDUCTOR_CONF = "/etc/conductor.conf";

    /**
     * Number of spaces to use as indentation on the Nginx ENV block.
     */
    const SPACES_ENV_INDENT = 8;

    /**
     * CLI boolean value const.
     */
    const OPTION_YES = "y";
    const OPTION_NO = "n";

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

        $this->mysql = MysqlPdo::connect('information_schema', $this->conf->mysql->username,
            $this->conf->mysql->password, $this->conf->mysql->host);
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
        $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (file_exists($crontab)) {
            $this->writeln('Backing up crontab file...');
            $this->call('cp ' . $crontab . ' ' . $this->conf->paths->temp . '/' . $this->appname . '/');
        }
        $appconf = $this->conf->paths->appconfs . '/conductor_' . $this->appname;
        if (file_exists($appconf)) {
            $this->writeln('Backing up Nginx virtualhost config...');
            $this->call('cp ' . $appconf . ' ' . $this->conf->paths->temp . '/' . $this->appname . '/');
        }
        $this->writeln('Compressing backup archive...');
        $this->call('tar - zcf ' . $this->conf->paths->temp . ' / ' . $filename . ' - C ' . $this->conf->paths->temp . ' / ' . $this->appname . ' / .');
        $this->writeln('Cleaning up...');
        $this->call('rm -Rf ' . $this->conf->paths->temp . ' / ' . $this->appname);
        $this->call('mv ' . $this->conf->paths->temp . ' / ' . $filename . ' ' . $this->conf->paths->backups . ' / ' . $filename);
    }

    /**
     * Creates a new MySQL user and database.
     * @param string $db_pass The password of which to use for the user account.
     * @return void
     */
    private function createMySQL($db_pass)
    {
        $this->appNameRequired();

        $this->mysql->exec('CREATE DATABASE if NOT EXISTS `db_' . $this->appname . '`;');
        $this->mysql->exec('GRANT ALL ON `db_' . $this->appname . '` .* TO \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\' IDENTIFIED BY \'' . $db_pass . '\';');
        $this->mysql->exec('FLUSH PRIVILEGES;');

        $this->writeln();
        $this->writeln('MySQL Database and User Details:');
        $this->writeln();
        $this->writeln(' DB Name:      db_' . $this->appname);
        $this->writeln(' DB Host:      ' . $this->conf->mysql->host);
        $this->writeln(' DB Username:  ' . $this->appname);
        $this->writeln(' DB Password:  ' . $db_pass);
        $this->writeln();

        // For convenience we'll add these DB params to the ENV vars with the benefit of using default Laravel ENV var names .
        $this->call('/usr/bin/conductor envars ' . $this->appname . ' --DB_HOST="' . $this->conf->mysql->host . '" --DB_DATABASE="db_' . $this->appname . '" --DB_USERNAME="' . $this->appname . '"  --DB_PASSWORD="' . $db_pass . '"');
    }

        /**
         * Destroys the database and user for the current application.
         * @return void
         */
        private function destroyMySQL()
        {
            $this->appNameRequired();
            if ($this->mysql->query('SHOW DATABASES LIKE \'db_' . $this->appname . '\';')->fetchObject()) {
                $this->writeln('Detected a Application MySQL user and database...');
                $this->mysql->exec('DROP DATABASE IF EXISTS `db_' . $this->appname . '`;');
                $this->mysql->exec('DROP USER \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\';');
                $this->mysql->exec('FLUSH PRIVILEGES;');
            }
        }

        /**
         * If detected as a Laravel application will attempt to migrate it based on it's framework version number.
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

            if ((strtolower($this->getCommand(1)) == 'envars') and (count($this->options()) > 0)) {
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
                $ammended_vhost_conf = $this->replaceBetweenSections('# START APPLICATION ENV VARIABLES',
                    '# END APPLICATION ENV VARIABLES',
                    file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf'),
                    $this->envConfigurationBlock($env_handler));
                file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $ammended_vhost_conf);
                file_put_contents($this->conf->paths->apps . '/' . $this->appname . '/.env',
                    $this->envFileLaravelConfiguration($env_handler));
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
                    $block .= sprintf(str_repeat(' ', self::SPACES_ENV_INDENT) . "fastcgi_param    %s    %s;" . PHP_EOL,
                        $key, $value);
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
                    $block .= $key . "=" . $value . PHP_EOL;
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
         * Generates an SSH deployment key (PPK) for the specified application.
         */
        public function createDeploymentKey()
        {
            $this->appNameRequired();
            $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
            $cmd_replacements = [
                '__PATH__' => $deploy_key_path,
                '__COMMENT__' => 'deploy@' . $this->appname . '.' . gethostname(),
            ];

            if (file_exists($deploy_key_path)) {
                $this->writeln('Private key already exists at: ' . $deploy_key_path);
                $this->writeln('Use \'conductor delkey {name}\' to remove it first or you can re-use it.');
                $this->writeln();
                $this->endWithError();
            }

            $this->call(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                $this->conf->cmdtpls->sshkeygen));

            if (!file_exists($deploy_key_path)) {
                $this->writeln('An error occurred and the deployment key could not be generated!');
                $this->endWithError();
            }

            foreach ([$deploy_key_path, $deploy_key_path . '.pub'] as $keyfile) {
                $this->call('/usr/bin/chown ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $keyfile);
                $this->call('/usr/bin/chmod 0700 ' . $keyfile);
            }

            $this->writeln('Deployment key has been generated successfully!');
            $this->writeln();
            $this->writeln(file_get_contents($deploy_key_path . '.pub'));
            $this->writeln();
            $this->writeln('Copy and paste the above public key content to your remote service(s) as required.');
            $this->writeln();
            $this->writeln('You can review this public key in future by running:');
            $this->writeln('   cat ' . $deploy_key_path . '.pub');
            $this->writeln();
            $this->writeln();
        }

        /**
         * Deletes an SSH deployment key (PPK) for a specific application.
         */
        public function deleteDeploymentKey()
        {
            $this->appNameRequired();
            $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
            if (file_exists($deploy_key_path)) {
                foreach ([$deploy_key_path, $deploy_key_path . '.pub'] as $keyfile) {
                    unlink($keyfile);
                }
                $this->writeln('Deleted the deployment key: ' . $deploy_key_path);
            } else {
                $this->writeln('No private key found at: ' . $deploy_key_path);
            }
        }

        /**
         * Request the provision or renewal of LetsEncrypt SSL certificates for a specific application.
         */
        public function generateLetsEncryptCertificate()
        {
            $this->appNameRequired();

            if ($this->isFlagSet('delete')) {
                $cmd_replacements = [
                    '__APP__' => $this->appname,
                ];
                $this->call(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                    $this->conf->cmdtpls->letsencryptdel));
                $this->writeln('');
                $this->writeln('Remember to update your application virtualhost configuration');
                $this->writeln('to comment out the HTTPS blocks before restarting Nginx!');
                $this->writeln();
                $this->endWithSuccess();
            }

            if ($this->isFlagSet('force-renew')) {
                $cmd_replacements = [
                    '__APP__' => $this->appname,
                    '__NGINX_RELOAD_CMD__' => $this->conf->services->nginx->reload,
                ];
                $this->call(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                    $this->conf->cmdtpls->letsencryptforcerenew));
                $this->writeln();
                $this->endWithSuccess();
            }

            $conf_path = "/etc/conductor/configs/{$this->appname}.conf";
            if (!file_exists($conf_path)) {
                $this->writeln('Configuration file not found at: ' . $conf_path);
                $this->endWithError();
            }
            $conf_content = file_get_contents($conf_path);
            $managed_domains = null;
            if (preg_match('/:: Managed domains: \[(.*?)\]/', $conf_content, $match) == 1) {
                $managed_domains = $match[1];
            }

            if (!$managed_domains) {
                $this->writeln('No managed domains found!');
                $this->endWithError();
            }
            $domains = rtrim(str_replace(' ', ',', $managed_domains), ',');

            $deploy_key_path = $this->conf->paths->deploykeys . '/' . $this->appname . '.deploykey';
            $cmd_replacements = [
                '__APP__' => $this->appname,
                '__NGINX_RELOAD_CMD__' => $this->conf->services->nginx->reload,
                '__DOMAINS__' => $domains,
                '__EMAIL__' => $this->conf->admin->email,
            ];

            $this->call(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                $this->conf->cmdtpls->letsencryptgen));
            $this->writeln();
            $this->writeln('If you wish to delete this certificate in future you can run:');
            $this->writeln('   conductor letsencrypt ' . $this->appname . ' --delete');
            $this->writeln();
            $this->writeln('If required, remember to uncomment and configure your virtualhost configuration file');
            $this->writeln('in order to use the SSL certificates as required.');
            $this->writeln();
            $this->endWithSuccess();
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

            $option_yes_no_set = [self::OPTION_YES, self::OPTION_NO];

            if (!$this->getOption('fqdn')) {
                // Entering interactive mode...
                $domain = $this->input('Domains (FQDN\'s) to map this application to:');
                $apppath = $this->input('Hosted directory:', '/public');
                $environment = $this->input('Environment type:', 'production');
                $mysql_req = $this->input('Provision a MySQL database?', self::OPTION_NO, $option_yes_no_set);
                $deploy_git = $this->input('Deploy application with Git now?', self::OPTION_NO, $option_yes_no_set);
                $generate_keys = $this->input('Create an SSH deployment key pair now?', self::OPTION_YES,
                    $option_yes_no_set);
            } else {
                // FQDN is set, entering non-interactive mode!
                $domain = $this->getOption('fqdn');
                $environment = $this->getOption('environment', 'production');
                $apppath = $this->getOption('path', '/public');
                $mysql_req = self::OPTION_NO; // Disable this by default.
                $deploy_git = self::OPTION_NO; // Disable this by default.
                $generate_keys = self::OPTION_NO; // Disable this by default.

                if ($this->getOption('mysql-pass')) {
                    $mysql_req = self::OPTION_YES;
                    $password = $this->getOption('mysql-pass');
                }

                if ($this->getOption('git-uri')) {
                    $deploy_git = self::OPTION_YES;
                    $gitrepo = $this->getOption('git-uri');
                }

                if ($this->isFlagSet('genkey')) {
                    $generate_keys = self::OPTION_YES;
                }
            }

            // Trim any trailing slash from the $path variable...
            $apppath = rtrim($apppath, '/');

            if (strtolower($deploy_git) == self::OPTION_YES) {
                if (!isset($gitrepo)) {
                    $this->writeln();
                    $gitrepo = $this->input('Git repository URL:');
                    $this->writeln();
                }
            }

            // Copy the virtualhost configuration file to our application configuration directory.
            copy($this->conf->paths->templates . '/vhost_template.tpl',
                $this->conf->paths->appconfs . '/' . $this->appname . '.conf');

            $domains = explode(' ', $domain);

            $placeholders = [
                '@@DOMAIN@@' => $domain,
                '@@DOMAIN_FIRST@@' => $domains[0],
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

            $cron_file = $this->conf->paths->crontab . '/conductor_' . $this->appname;
            copy($this->conf->paths->templates . '/cron_template.tpl',
                $this->conf->paths->crontab . '/conductor_' . $this->appname);
            $placeholders = [
                '@@APPNAME@@' => $this->appname,
                '@@APPPATH@@' => $this->appname . $apppath,
            ];
            $cron_config = file_get_contents($cron_file);
            foreach ($placeholders as $placeholder => $value) {
                $cron_config = str_replace($placeholder, $value, $cron_config);
            }
            file_put_contents($cron_file, $cron_config);
            $this->call('chown -R root:root ' . $cron_file);
            chmod($cron_file, 744);

            // Load  the application environment configuration in to the application configuration (which will create the initial ENV configuration)...
            //$this->updateEnvVars();
            $this->call('/usr/bin/conductor envars ' . $this->appname . ' APP_ENV="' . $environment . '"');

            // Enable the site by reloading Nginx.
            //$this->call($this->conf->services->nginx->reload);

            if (strtolower($deploy_git) == self::OPTION_YES) {
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

            if (strtolower($mysql_req) == self::OPTION_YES) {
                $this->writeln();
                if (!isset($password)) {
                    $password = $this->input('Please enter a password for the MySQL database:');
                }
                $this->createMySQL($password);
            }

            if (strtolower($generate_keys) == self::OPTION_YES) {
                $this->writeln('Generating a deployment (SSH) key pair...');
                $this->createDeploymentKey();
            }

            $this->migrateLaravel($environment);
        }

        /**
         * Opens the configured text editor to edit the Nginx configuration file for a specific app.
         */
        public function editApplicationConfig()
        {
            $this->appNameRequired();

            $config_path = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
            if (!file_exists($config_path)) {
                $this->writeln('Virtual host configuration not found at: ' . $config_path);
            }
            system($this->conf->binaries->editor . ' ' . $config_path . ' > `tty`');
            $this->writeln('Checking file updates for Nginx configuration issues...');
            $this->writeln();
            $this->call($this->conf->binaries->nginx . ' -t');
            $this->writeln();
            $this->writeln('** Remember to restart/reload Nginx for any changes to take affect! ** ');
            $this->writeln();
        }

        /**
         * Opens the application managed cron file.
         */
        public function editApplicationCron()
        {
            $this->appNameRequired();

            $cron_path = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
            if (!file_exists($cron_path)) {
                $this->writeln('Application cron not found at: ' . $cron_path);
            }
            system($this->conf->binaries->editor . ' ' . $cron_path . ' > `tty`');
            $this->writeln();
        }

        /**
         * Updates the code and executes migrations on an existing database.
         */
        public function updateApplication()
        {
            $this->appNameRequired();

            // Get the current environment type to execute the Laravel migrations with.
            $env_handler = new EnvHandler($this->conf->paths->appconfs . '/' . $this->appname . '_envars.json');
            $env_handler->load();
            $environment = $env_handler->get('APP_ENV', 'production');

            // Checks for CLI options to suppress the 'stop' application user input.
            if ($this->getOption('down', false)) {
                if ($this->getOption('down') == "true") {
                    $stopapp = self::OPTION_YES;
                } else {
                    $stopapp = self::OPTION_NO;
                }
            }

            if (!file_exists($this->appdir)) {
                $this->writeln('Application was not found on this server!');
                $this->endWithError();
            }

            if (!isset($stopapp)) {
                $stopapp = $this->input('Do you wish to \'stop\' the application before upgrading?', self::OPTION_YES,
                    [self::OPTION_YES, self::OPTION_NO]);
            }

            if (strtolower($stopapp) == self::OPTION_YES) {
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
            if (strtolower($stopapp) == self::OPTION_YES) {
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
                if ($application->isDir() and ($application->getBasename()[0] != '.')) {
                    $this->writeln(' - ' . $application->getBasename());
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

            $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
            if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname)) {
                unlink($crontab); // Delete existing crontab file if it exists!
                mv($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname, $crontab);
                chmod($crontab, 744);
                $this->call('chown root:root ' . $crontab);
                $this->writeln('Finished importing the application crontab!');
            } else {
                $this->writeln('No Conductor crontab was found, skipping cron import!');
            }

            if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz')) {
                $this->writeln('Importing application MySQL database...');
                $this->call('gunzip < ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
                $this->writeln('Finished importing the MySQL database!');
                unlink($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz');
            } else {
                $this->writeln('No Conductor database archive was found, skipping DB import!');
            }

            $appconf = $this->conf->paths->appconfs . '/' . $this->appname.'.conf';
            if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/'.$this->appname.'.conf')) {
                unlink($appconf); // Delete existing nginx config file if it exists!
                mv($this->conf->paths->temp . '/restore_' . $this->appname . '/'.$this->appname.'.conf', $appconf);
                chmod($appconf, 744);
                $this->call('chown root:root ' . $crontab);
                $this->writeln('Finished importing the application (nginx) configuration!');
            } else {
                $this->writeln('No application (nginx) configuration was found, skipping virtualhost config import!');
            }

            $this->call('rm -Rf ' . $this->appdir);
            $this->call('cp -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/ ' . $this->appdir . '/');
            $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->appdir);
            $this->call('rm -Rf ' . $this->conf->paths->temp . '/restore_' . $this->appname);
            $this->writeln('Restarting Nginx...');
            $this->call($this->conf->services->nginx->restart);
            $this->writeln('...finished!');
            $this->startLaravelApplication();
            $this->endWithSuccess();
        }

        /**
         * Rollback the current application to it's state prior to the upgrade.
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

            $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
            if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname)) {
                unlink($crontab); // Delete existing crontab file if it exists!
                mv($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname, $crontab);
                chmod($crontab, 744);
                $this->call('chown root:root ' . $crontab);
                $this->writeln('Finished importing the application crontab!');
            } else {
                $this->writeln('No Conductor crontab was found, skipping cron import!');
            }

            if (file_exists($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz')) {
                $this->writeln('Importing application MySQL database...');
                $this->call('gunzip < ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
                $this->writeln('Finished importing the MySQL database!');
                unlink($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz');
            } else {
                $this->writeln('No Conductor database archive was found, skipping DB import!');
            }

            $appconf = $this->conf->paths->appconfs . '/' . $this->appname.'.conf';
            if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/'.$this->appname.'.conf')) {
                unlink($appconf); // Delete existing nginx config file if it exists!
                mv($this->conf->paths->temp . '/restore_' . $this->appname . '/'.$this->appname.'.conf', $appconf);
                chmod($appconf, 744);
                $this->call('chown root:root ' . $crontab);
                $this->writeln('Finished importing the application (nginx) configuration!');
            } else {
                $this->writeln('No application (nginx) configuration was found, skipping virtualhost config import!');
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
                $this->writeln('Removing application crontab...');
                $this->call('rm ' . $this->conf->paths->crontabs . '/conductor_' . $this->appname);
                $this->writeln('Destroying application...');
                $this->call('rm ' . $this->conf->paths->appconfs . '/' . $this->appname . '*');
                $this->writeln('Reloading Nginx configuration...');
                $this->call($this->conf->services->nginx->reload);
                $this->writeln('Destroying MySQL database and associated users...');
                $this->destroyMySQL();
                $this->writeln('Destroying app directory and log files...');
                $this->call('rm -Rf ' . $this->appdir);
                $this->call('rm -Rf ' . $this->conf->paths->applogs . '/' . $this->appname);
                $this->writeln();
                $this->writeln('Deployment keys have been kept (if you wish to re-use them');
                $this->writeln('otherwise you can delete them too by running:');
                $this->writeln();
                $this->writeln('  conductor delkey ' . $this->appname);
                $this->writeln();
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
