<?php

require_once 'MysqlPdo.php';

/**
 * Conductor
 *
 * Conductor is a CLI tool to aid provisioning and maintenance of PHP-based sites and applications.
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
    const CONDUCTOR_VERSION = "5.0.0";

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

        $this->enforceCli();

        if (!$this->isSuperUser()) {
            $this->writeln('You must be root to use this tool!');
            $this->endWithError();
        }

        $this->conf = $this->conductorConfiguration();
        $this->checkDependencies();

        if ($this->mysqlEnabled()) {
            $this->mysql = MysqlPdo::connect('information_schema', $this->conf->mysql->username,
                $this->conf->mysql->password, $this->conf->mysql->host);
        }
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
            'posix' => 'The PHP POSIX extention is required but is missing',
            'json' => 'The PHP JSON extention is required but is missing',
        ];

        if ($this->mysqlEnabled()) {
            $depends['pdo_mysql'] = 'The PHP MySQL PDO extension is required but is missing';
        }

        foreach ($depends as $function => $dependency) {
            if (!extension_loaded($function)) {
                $this->writeln($dependency);
                $this->endWithError();
            }
        }
    }

    /**
     * Checks whether Conductor should manage local MySQL databases.
     * @return boolean
     */
    private function mysqlEnabled()
    {
        if (!isset($this->conf->mysql->enabled)) {
            return true;
        }

        return (bool) $this->conf->mysql->enabled;
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
     * Action Fail2Ban IP ban management commands.
     * @param string $actionOrIp
     */
    public function banControl($actionOrIp)
    {
        $this->ensureFail2BanClient();

        if ($actionOrIp == 'list') {
            $this->listBannedIps();
            return;
        }

        if ($actionOrIp == 'purge') {
            $this->purgeBannedIps();
            return;
        }

        $this->banIpAddress($actionOrIp);
    }

    /**
     * Unban an IP address from every active Fail2Ban jail.
     * @param string $ip_address
     */
    public function unbanIpAddress($ip_address)
    {
        $this->ensureFail2BanClient();
        $this->validateIpAddress($ip_address);

        $removed = 0;
        foreach ($this->fail2BanJails() as $jail) {
            $output = [];
            $this->runFail2BanClient(['get', $jail, 'banip'], $output);
            if (!in_array($ip_address, $this->extractIpAddresses(implode(' ', $output)))) {
                continue;
            }

            $unban_output = [];
            if ($this->runFail2BanClient(['set', $jail, 'unbanip', $ip_address], $unban_output) === 0) {
                $removed++;
            }
        }

        if ($removed === 0) {
            $this->writeln('The IP address was not banned in any active Fail2Ban jail.');
            return;
        }

        $this->writeln('Unbanned ' . $ip_address . ' from ' . $removed . ' Fail2Ban jail(s).');
    }

    /**
     * Validate that Fail2Ban is available.
     */
    private function ensureFail2BanClient()
    {
        $output = [];
        exec('command -v fail2ban-client 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0) {
            $this->writeln('Fail2Ban is not installed, run: utils/install_fail2ban_iptables.sh to enable these features!');
            $this->endWithError();
        }
    }

    /**
     * Ban an IP address manually until it is explicitly unbanned or purged.
     * @param string $ip_address
     */
    private function banIpAddress($ip_address)
    {
        $this->validateIpAddress($ip_address);

        $jail = 'conductor-manual';
        if (!in_array($jail, $this->fail2BanJails())) {
            $this->writeln('The conductor-manual Fail2Ban jail is not active. Re-run the optional installer and restart Fail2Ban.');
            $this->endWithError();
        }

        $output = [];
        if ($this->runFail2BanClient(['set', $jail, 'banip', $ip_address], $output) !== 0) {
            $this->writeln('Unable to ban ' . $ip_address . ': ' . implode(' ', $output));
            $this->endWithError();
        }

        $this->writeln('Banned ' . $ip_address . ' in the ' . $jail . ' jail.');
    }

    /**
     * Show all IP addresses currently banned by Fail2Ban.
     */
    private function listBannedIps()
    {
        $rows = [];
        foreach ($this->fail2BanJails() as $jail) {
            $output = [];
            if ($this->runFail2BanClient(['get', $jail, 'banip', '--with-time'], $output) !== 0) {
                $output = [];
                $this->runFail2BanClient(['get', $jail, 'banip'], $output);
            }

            $ips = $this->extractIpAddresses(implode(' ', $output));
            if (empty($ips)) {
                continue;
            }

            $ban_time = $this->fail2BanJailBanTime($jail);
            foreach ($ips as $ip_address) {
                $rows[] = [$ip_address, $jail, $ban_time];
            }
        }

        if (empty($rows)) {
            $this->writeln('No IP addresses are currently banned by Fail2Ban.');
            return;
        }

        $this->writeln(str_pad('IP address', 40) . str_pad('Jail', 30) . 'Ban time');
        $this->writeln(str_repeat('-', 80));
        foreach ($rows as $row) {
            $this->writeln(str_pad($row[0], 40) . str_pad($row[1], 30) . $row[2]);
        }
    }

    /**
     * Clear every IP address currently banned by Fail2Ban.
     */
    private function purgeBannedIps()
    {
        $removed = 0;
        foreach ($this->fail2BanJails() as $jail) {
            $output = [];
            $this->runFail2BanClient(['get', $jail, 'banip'], $output);
            foreach ($this->extractIpAddresses(implode(' ', $output)) as $ip_address) {
                $unban_output = [];
                if ($this->runFail2BanClient(['set', $jail, 'unbanip', $ip_address], $unban_output) === 0) {
                    $removed++;
                }
            }
        }

        $this->writeln('Purged ' . $removed . ' banned IP address entr' . ($removed === 1 ? 'y.' : 'ies.'));
    }

    /**
     * Get all active Fail2Ban jails.
     * @return array
     */
    private function fail2BanJails()
    {
        $output = [];
        if ($this->runFail2BanClient(['status'], $output) !== 0) {
            $this->writeln('Unable to read Fail2Ban status.');
            $this->endWithError();
        }

        if (!preg_match('/Jail list:\s*(.+)$/m', implode(PHP_EOL, $output), $matches)) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $matches[1])));
    }

    /**
     * Get a human-readable configured ban time for a jail.
     * @param string $jail
     * @return string
     */
    private function fail2BanJailBanTime($jail)
    {
        $output = [];
        if ($this->runFail2BanClient(['get', $jail, 'bantime'], $output) !== 0 || empty($output)) {
            return 'unknown';
        }

        $seconds = (int) trim($output[0]);
        if ($seconds < 0) {
            return 'permanent';
        }

        if ($seconds >= 86400 && $seconds % 86400 === 0) {
            return ($seconds / 86400) . ' day(s)';
        }

        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            return ($seconds / 3600) . ' hour(s)';
        }

        if ($seconds >= 60 && $seconds % 60 === 0) {
            return ($seconds / 60) . ' minute(s)';
        }

        return $seconds . ' second(s)';
    }

    /**
     * Execute fail2ban-client safely.
     * @param array $arguments
     * @param array $output
     * @return int
     */
    private function runFail2BanClient($arguments, &$output)
    {
        $command = 'fail2ban-client';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $output, $exit_code);
        return $exit_code;
    }

    /**
     * Extract valid IPv4 and IPv6 addresses from command output.
     * @param string $text
     * @return array
     */
    private function extractIpAddresses($text)
    {
        preg_match_all('/(?:\d{1,3}\.){3}\d{1,3}|(?:[a-f0-9]{0,4}:){2,}[a-f0-9]{0,4}/i', $text, $matches);

        $ips = [];
        foreach ($matches[0] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ips[] = $candidate;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Validate a CLI-provided IP address.
     * @param string $ip_address
     */
    private function validateIpAddress($ip_address)
    {
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $this->writeln('Invalid IP address: ' . $ip_address);
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
     * Validate Nginx and optionally reload it gracefully.
     * @param string $change_description
     */
    private function promptGracefulNginxReload($change_description = 'change')
    {
        $this->writeln('Checking Nginx configuration...');
        if ($this->callWithExitCode($this->conf->binaries->nginx . ' -t') !== 0) {
            $this->writeln('Nginx configuration test failed. Please fix the configuration before reloading Nginx.');
            $this->endWithError();
        }

        $reload_nginx = $this->input('Gracefully restart (reload) Nginx now for the ' . $change_description . ' to take effect?',
            self::OPTION_YES, [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($reload_nginx) == self::OPTION_YES) {
            $this->writeln('Gracefully restarting (reloading) Nginx...');
            $this->call($this->conf->services->nginx->reload);
        } else {
            $this->writeln('Remember to gracefully restart (reload) Nginx before the ' . $change_description . ' will take effect.');
        }
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
        if ($this->mysqlEnabled() && $this->mysql->query('SHOW DATABASES LIKE \'db_' . $this->appname . '\';')->fetchObject()) {
            $this->writeln('Detected a MySQL database, backing it up...');
            $this->call($this->conf->binaries->mysqldump . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' --no-create-db db_' . $this->appname . ' | ' . $this->conf->binaries->gzip . ' -c | cat > ' . $this->conf->paths->temp . '/' . $this->appname . '/appdb.sql.gz');
        }
        $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (file_exists($crontab)) {
            $this->writeln('Backing up crontab file...');
            $this->call('cp ' . $crontab . ' ' . $this->conf->paths->temp . '/' . $this->appname . '/');
        }
        $appconf = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (file_exists($appconf)) {
            $this->writeln('Backing up Nginx virtualhost config...');
            $this->call('cp ' . $appconf . ' ' . $this->conf->paths->temp . '/' . $this->appname . '/');
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
        if (!$this->mysqlEnabled()) {
            $this->writeln('MySQL management is disabled in /etc/conductor.conf; skipping database provisioning.');
            return;
        }

        // Creating the user and granting privileges in separate statements (rather than the legacy
        // combined `GRANT ... IDENTIFIED BY`) is required since MySQL 8.0 removed that syntax entirely;
        // this form still works fine on MariaDB too.
        $this->mysql->exec('CREATE DATABASE if NOT EXISTS `db_' . $this->appname . '`;');
        $this->mysql->exec('CREATE USER IF NOT EXISTS \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\' IDENTIFIED BY \'' . $db_pass . '\';');
        $this->mysql->exec('GRANT ALL ON `db_' . $this->appname . '`.* TO \'' . $this->appname . '\'@\'' . $this->conf->mysql->confrom . '\';');
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
        if (!$this->mysqlEnabled()) {
            return;
        }

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
     * Replaces the complete lines between two marker lines, preserving the markers themselves.
     * @param string $content
     * @param string $start_marker
     * @param string $end_marker
     * @param callable $line_handler
     * @return string
     */
    private function replaceLinesBetweenMarkers($content, $start_marker, $end_marker, $line_handler)
    {
        $lines = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $in_section = false;

        for ($i = 0; $i < count($lines); $i += 2) {
            if (strpos($lines[$i], $start_marker) !== false) {
                $in_section = true;
                continue;
            }

            if (strpos($lines[$i], $end_marker) !== false) {
                $in_section = false;
                continue;
            }

            if ($in_section) {
                $lines[$i] = call_user_func($line_handler, $lines[$i]);
            }
        }

        return implode('', $lines);
    }

    /**
     * Comments a Nginx configuration line unless it is already commented or blank.
     * @param string $line
     * @return string
     */
    private function commentNginxConfigLine($line)
    {
        if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
            return $line;
        }

        return preg_replace('/^(\s*)/', '$1#', $line, 1);
    }

    /**
     * Uncomments a Nginx configuration line unless it is a Conductor marker comment.
     * @param string $line
     * @return string
     */
    private function uncommentNginxConfigLine($line)
    {
        if (strpos($line, '# -- C:') !== false) {
            return $line;
        }

        return preg_replace('/^(\s*)#\s?/', '$1', $line, 1);
    }

    /**
     * Enables the SSL listener/certificate section in an application virtualhost configuration.
     * @param string $config_path
     * @return void
     */
    private function enableApplicationSslConfig($config_path)
    {
        $config = file_get_contents($config_path);

        foreach ([
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
        ] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            [$this, 'commentNginxConfigLine']
        );

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
            [$this, 'uncommentNginxConfigLine']
        );

        file_put_contents($config_path, $config);
    }

    /**
     * Disables the SSL listener/certificate section in an application virtualhost configuration.
     * @param string $config_path
     * @return void
     */
    private function disableApplicationSslConfig($config_path)
    {
        $config = file_get_contents($config_path);

        foreach ([
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
        ] as $marker) {
            if (strpos($config, $marker) === false) {
                $this->writeln('Could not find required Conductor marker in virtualhost configuration: ' . $marker);
                $this->endWithError();
            }
        }

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Default (HTTP) Main Block -- #',
            '# -- C:End Default (HTTP) Main Block -- #',
            [$this, 'uncommentNginxConfigLine']
        );

        $config = $this->replaceLinesBetweenMarkers(
            $config,
            '# -- C:Start Auto-LetsEncrypt Main Block -- #',
            '# -- C:End Auto-LetsEncrypt Main Block -- #',
            [$this, 'commentNginxConfigLine']
        );

        file_put_contents($config_path, $config);
    }

    /**
     * Apply an SSL vhost configuration change, validate Nginx, and optionally restart it.
     * @param string $config_path
     * @param string $action
     * @return void
     */
    private function updateApplicationSslConfig($config_path, $action)
    {
        $original_conf_content = file_get_contents($config_path);

        if ($action == 'enable') {
            $this->enableApplicationSslConfig($config_path);
        } elseif ($action == 'disable') {
            $this->disableApplicationSslConfig($config_path);
        } else {
            $this->writeln('Unknown SSL configuration action: ' . $action);
            $this->endWithError();
        }

        $this->writeln('Updated virtualhost configuration: ' . $config_path);
        $this->writeln('Checking Nginx configuration...');
        $nginx_test_exit_code = $this->callWithExitCode($this->conf->binaries->nginx . ' -t');

        if ($nginx_test_exit_code !== 0) {
            file_put_contents($config_path, $original_conf_content);
            $this->writeln();
            $this->writeln('Nginx configuration test failed; restored the previous virtualhost configuration.');
            $this->writeln();
            $this->endWithError();
        }

        if ($action == 'enable') {
            $this->writeln('SSL configuration has been enabled.');
        } else {
            $this->writeln('SSL configuration has been reset.');
        }

        $reload_nginx = $this->input('Nginx configuration test passed. Gracefully restart (reload) Nginx now?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($reload_nginx) == self::OPTION_YES) {
            $this->writeln('Gracefully restarting (reloading) Nginx...');
            $this->call($this->conf->services->nginx->reload);
        } else {
            $this->writeln('Remember to gracefully restart (reload) Nginx before the SSL configuration change will take effect.');
        }
    }

    /**
     * Returns the virtualhost configuration path for the selected application.
     * @return string
     */
    private function applicationConfigPath()
    {
        $conf_path = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';

        if (!file_exists($conf_path)) {
            $this->writeln('Configuration file not found at: ' . $conf_path);
            $this->endWithError();
        }

        return $conf_path;
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
                @unlink($keyfile);
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

        if ($this->isFlagSet('enable')) {
            $this->updateApplicationSslConfig($this->applicationConfigPath(), 'enable');
            $this->writeln();
            $this->endWithSuccess();
        }

        if ($this->isFlagSet('disable')) {
            $this->updateApplicationSslConfig($this->applicationConfigPath(), 'disable');
            $this->writeln();
            $this->endWithSuccess();
        }

        if ($this->isFlagSet('delete')) {
            $conf_path = $this->applicationConfigPath();

            $cmd_replacements = [
                '__APP__' => $this->appname,
            ];

            $exit_code = $this->callWithExitCode(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
                $this->conf->cmdtpls->letsencryptdel));

            if ($exit_code !== 0) {
                $this->writeln();
                $this->writeln('The LetsEncrypt certificate delete request failed; leaving the virtualhost configuration unchanged.');
                $this->writeln();
                $this->endWithError();
            }

            $this->updateApplicationSslConfig($conf_path, 'disable');
            $this->writeln('SSL configuration has been disabled.');
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

        $conf_path = $this->applicationConfigPath();
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

        $cmd_replacements = [
            '__APP__' => $this->appname,
            '__NGINX_RELOAD_CMD__' => $this->conf->services->nginx->reload,
            '__DOMAINS__' => $domains,
            '__EMAIL__' => $this->conf->admin->email,
        ];

        $exit_code = $this->callWithExitCode(str_replace(array_keys($cmd_replacements), array_values($cmd_replacements),
            $this->conf->cmdtpls->letsencryptgen));

        if ($exit_code !== 0) {
            $this->writeln();
            $this->writeln('The LetsEncrypt certificate request failed; leaving the virtualhost configuration unchanged.');
            $this->writeln();
            $this->endWithError();
        }

        $enable_ssl = $this->input('LetsEncrypt certificate request successful. Enable SSL configuration now?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($enable_ssl) == self::OPTION_YES) {
            $this->updateApplicationSslConfig($conf_path, 'enable');
        }

        $this->writeln();
        $this->writeln('If you wish to delete this certificate in future you can run:');
        $this->writeln('   conductor letsencrypt ' . $this->appname . ' --delete');
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

        $vhost_template = $this->getOption('template', $this->conf->admin->default_template);
        $is_proxy_template = strtolower($vhost_template) == 'proxy';
        if(!file_exists($tmpl = $this->conf->paths->templates.'/templates/vhost_' . strtolower($vhost_template).'.tpl')){
            $this->writeln('The configuration template was not found!');
            $this->endWithError();
        }

        $gitbranch = $this->getOption('git-branch', 'master');
        if (!$this->getOption('fqdn')) {
            // Entering interactive mode...
            $domain = $this->input('Domains (FQDN\'s) to map this application to:');
            $apppath = $is_proxy_template ? '' : $this->input('Hosted directory:', '/public');
            $environment = $this->input('Environment type:', 'production');
            $mysql_req = $this->mysqlEnabled()
                ? $this->input('Provision a MySQL database?', self::OPTION_NO, $option_yes_no_set)
                : self::OPTION_NO;
            $deploy_git = $this->input('Deploy application with Git now?', self::OPTION_NO, $option_yes_no_set);
            $generate_keys = $this->input('Create an SSH deployment key pair now?', self::OPTION_YES,
                $option_yes_no_set);
        } else {
            // FQDN is set, entering non-interactive mode!
            $domain = $this->getOption('fqdn');
            $environment = $this->getOption('environment', 'production');
            $apppath = $is_proxy_template ? '' : $this->getOption('path', '/public');
            $mysql_req = self::OPTION_NO; // Disable this by default.
            $deploy_git = self::OPTION_NO; // Disable this by default.
            $generate_keys = self::OPTION_NO; // Disable this by default.

            if ($this->mysqlEnabled() && $this->getOption('mysql-pass')) {
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
                $gitrepo = $this->input('Git repository URI (eg. git@github.com:user/repo.git):');
                $gitbranch = $this->input('Git branch [master]: ');
                $this->writeln();
            }
        }

        // Copy the virtualhost configuration file to our application configuration directory.
        copy($tmpl, $this->conf->paths->appconfs . '/' . $this->appname . '.conf');

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
            '@@VERSION@@' => $this->version(),
            '@@CREATED_AT@@' => date('c'),
        ];
        $config = file_get_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf');
        foreach ($placeholders as $placeholder => $value) {
            $config = str_replace($placeholder, $value, $config);
        }
        file_put_contents($this->conf->paths->appconfs . '/' . $this->appname . '.conf', $config);

        mkdir($this->appdir, 0755);
        mkdir($this->conf->paths->applogs . '/' . $this->appname, 0755);
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->conf->paths->applogs . '/' . $this->appname);
        $this->call('/usr/bin/chmod 755 ' .$this->conf->paths->appconfs . '/' . $this->appname . '.conf');

        $cron_file = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        copy($this->conf->paths->templates . '/cron_template.tpl',
            $this->conf->paths->crontabs . '/conductor_' . $this->appname);
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
        $this->call('chmod 744 ' . $cron_file);

        // Load  the application environment configuration in to the application configuration (which will create the initial ENV configuration)...
        //$this->updateEnvVars();
        $this->call('/usr/bin/conductor envars ' . $this->appname . ' APP_ENV="' . $environment . '"');

        // Enable the site by reloading Nginx.
        //$this->call($this->conf->services->nginx->reload);

        if (strtolower($deploy_git) == self::OPTION_YES) {
            $this->writeln('We\'ll now deploy your application using Git...');
            $this->call('rm -Rf ' . $this->appname);
            $this->call($this->conf->binaries->git . ' clone ' . $gitrepo . ' ' . $this->appdir);
            $this->call($this->conf->binaries->git . ' checkout ' . $gitbranch);
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

        if ($is_proxy_template) {
            foreach ([502, 503, 504] as $status_code) {
                $proxy_error_template = $this->conf->paths->templates . '/templates/' . $status_code . '.html.tpl';
                if (!file_exists($proxy_error_template)) {
                    $this->writeln('The proxy error page template was not found: ' . $proxy_error_template);
                    $this->endWithError();
                }

                $error_page_path = $this->appdir . '/.' . $status_code . '.html';
                copy($proxy_error_template, $error_page_path);
                $error_page = file_get_contents($error_page_path);
                $error_page = str_replace('@@APPNAME@@', $this->appname, $error_page);
                file_put_contents($error_page_path, $error_page);
            }
        } else {
            $conductor_page_template = $this->conf->paths->templates . '/templates/conductor.html.tpl';
            if (!file_exists($conductor_page_template)) {
                $this->writeln('The Conductor placeholder page template was not found: ' . $conductor_page_template);
                $this->endWithError();
            }

            $document_root = rtrim($this->appdir . $apppath, '/');
            if (!file_exists($document_root)) {
                mkdir($document_root, 0755, true);
            }

            $conductor_page_path = $document_root . '/conductor.html';
            copy($conductor_page_template, $conductor_page_path);
            $conductor_page = file_get_contents($conductor_page_path);
            $conductor_page = str_replace('@@APPNAME@@', $this->appname, $conductor_page);
            file_put_contents($conductor_page_path, $conductor_page);
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
        $this->promptGracefulNginxReload('new application');
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
        $this->promptGracefulNginxReload('configuration change');
        $this->writeln();
    }

    /**
     * Enables the Nginx virtualhost configuration for a specific application.
     */
    public function enableApplication()
    {
        $this->appNameRequired();
        $this->toggleApplicationConfig(true);
    }

    /**
     * Disables the Nginx virtualhost configuration for a specific application.
     */
    public function disableApplication()
    {
        $this->appNameRequired();
        $this->toggleApplicationConfig(false);
    }

    /**
     * Rename an application config between active and disabled states.
     * @param bool $enable
     */
    private function toggleApplicationConfig($enable)
    {
        $enabled_path = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        $disabled_path = $this->conf->paths->appconfs . '/' . $this->appname . '.disabled';
        $from = $enable ? $disabled_path : $enabled_path;
        $to = $enable ? $enabled_path : $disabled_path;

        if (!file_exists($this->appdir)) {
            $this->writeln('Application was not found on this server!');
            $this->endWithError();
        }

        if (file_exists($to)) {
            $this->writeln('Application is already ' . ($enable ? 'enabled.' : 'disabled.'));
            return;
        }

        if (!file_exists($from)) {
            $this->writeln('Virtual host configuration not found at: ' . $from);
            $this->endWithError();
        }

        if (!rename($from, $to)) {
            $this->writeln('Unable to rename virtual host configuration.');
            $this->endWithError();
        }

        $this->writeln('Application ' . $this->appname . ' has been ' . ($enable ? 'enabled.' : 'disabled.'));

        $this->writeln('Checking Nginx configuration...');
        if ($this->callWithExitCode($this->conf->binaries->nginx . ' -t') !== 0) {
            rename($to, $from);
            $this->writeln('Nginx configuration test failed. The application has been returned to its previous state.');
            $this->endWithError();
        }

        $reload_nginx = $this->input('Gracefully restart (reload) Nginx now for the change to take effect?', self::OPTION_YES,
            [self::OPTION_YES, self::OPTION_NO]);

        if (strtolower($reload_nginx) == self::OPTION_YES) {
            $this->writeln('Gracefully restarting (reloading) Nginx...');
            $this->call($this->conf->services->nginx->reload);
        } else {
            $this->writeln('Remember to gracefully restart (reload) Nginx before the change will take effect.');
        }
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
        $this->writeln(str_pad('Status', 8) . 'Application');
        $this->writeln(str_repeat('-', 32));

        $application_names = [];
        foreach ($applications as $application) {
            if ($application->isDir() and ($application->getBasename()[0] != '.')) {
                $application_names[] = $application->getBasename();
            }
        }

        sort($application_names);
        foreach ($application_names as $application_name) {
            $this->writeln(str_pad($this->applicationEnabledMarker($application_name), 8) . $application_name);
        }

        $this->writeln();
    }

    /**
     * Return an enabled/disabled marker for listApplications().
     * @param string $application_name
     * @return string
     */
    private function applicationEnabledMarker($application_name)
    {
        $enabled_path = $this->conf->paths->appconfs . '/' . $application_name . '.conf';
        $disabled_path = $this->conf->paths->appconfs . '/' . $application_name . '.disabled';

        if (file_exists($enabled_path)) {
            return '[/]';
        }

        if (file_exists($disabled_path)) {
            return '[x]';
        }

        return '[?]';
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

        mkdir($this->appdir, 0755);
        mkdir($this->conf->paths->applogs . '/' . $this->appname, 0755);
        $this->call('chown -R ' . $this->conf->permissions->webuser . ':' . $this->conf->permissions->webgroup . ' ' . $this->conf->paths->applogs . '/' . $this->appname);

        mkdir($this->conf->paths->temp . '/restore_' . $this->appname, 755);
        $this->call('tar -zxf ' . $archive . ' -C ' . $this->conf->paths->temp . '/restore_' . $this->appname);

        $crontab = $this->conf->paths->crontabs . '/conductor_' . $this->appname;
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname)) {
            @unlink($crontab); // Delete existing crontab file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname . ' ' . $crontab);
            $this->call('chmod 744 ' . $crontab);
            $this->call('chown root:root ' . $crontab);
            $this->writeln('Finished importing the application crontab!');
        } else {
            $this->writeln('No Conductor crontab was found, skipping cron import!');
        }

        if ($this->mysqlEnabled() && file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            @unlink($this->conf->paths->temp . '/restore_' . $this->appname . '/appdb.sql.gz');
        } elseif (!$this->mysqlEnabled()) {
            $this->writeln('MySQL management is disabled, skipping DB import!');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $appconf = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf')) {
            @unlink($appconf); // Delete existing nginx config file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf' . ' ' . $appconf);
            $this->call('chmod 744 ' . $appconf);
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
            @unlink($crontab); // Delete existing crontab file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/conductor_' . $this->appname . ' ' . $crontab);
            $this->call('chmod 744 ' .$crontab);
            $this->call('chown root:root ' . $crontab);
            $this->writeln('Finished importing the application crontab!');
        } else {
            $this->writeln('No Conductor crontab was found, skipping cron import!');
        }

        if ($this->mysqlEnabled() && file_exists($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz')) {
            $this->writeln('Importing application MySQL database...');
            $this->call('gunzip < ' . $this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz | mysql -h' . $this->conf->mysql->host . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' db_' . $this->appname . '');
            $this->writeln('Finished importing the MySQL database!');
            unlink($this->conf->paths->temp . '/rollback_' . $this->appname . '/appdb.sql.gz');
        } elseif (!$this->mysqlEnabled()) {
            $this->writeln('MySQL management is disabled, skipping DB import!');
        } else {
            $this->writeln('No Conductor database archive was found, skipping DB import!');
        }

        $appconf = $this->conf->paths->appconfs . '/' . $this->appname . '.conf';
        if (file_exists($this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf')) {
            @unlink($appconf); // Delete existing nginx config file if it exists!
            $this->call('mv ' . $this->conf->paths->temp . '/restore_' . $this->appname . '/' . $this->appname . '.conf' . ' ' . $appconf);
            $this->call('chmod 744 ' . $appconf);
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
            $this->promptGracefulNginxReload('deleted application');
            if ($this->mysqlEnabled()) {
                $this->writeln('Destroying MySQL database and associated users...');
                $this->destroyMySQL();
            }
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
