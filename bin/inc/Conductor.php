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
        // Enforce CLI operation only!
        $this->enforceCli();
        // Load the conductor configuration files.
        $this->conf = $this->conductorConfiguration();

        // Set the applicaiton name and directory if this is set.
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
        //var_dump($action);
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
     * @return void
     */
    private function backupApplication()
    {
        $this->appNameRequired();
        $this->call('cp -R ' . $this->appdir . ' ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->writeln('Backing up MySQL database (if exists)...');
        $this->call($this->conf->binaries->mysqldump . ' -u' . $this->conf->mysql->username . ' -p' . $this->conf->mysql->password . ' --no-create-db db_' . $this->appname . ' | ' . $this->conf->binaries->gzip . ' -c | cat > ' . $this->conf->paths->temp . '/' . $this->appname . '/appdb.sql.gz');
        $this->writeln('Compressing backup archive...');
        $this->call('tar -zcf ' . $this->conf->paths->temp . '/' . $this->appname . ' -C ' . $this->conf->paths->temp . '/' . $this->appname . '/ .');
        $this->writeln('Cleaning up...');
        $this->call('rm -Rf ' . $this->conf->paths->temp . '/' . $this->appname);
        $this->call('mv ' . $this->conf->paths->temp . '/' . $this->appname . ' ' . $this->conf->paths->backups . '/' . $this->appname);
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

    /**
     * Migrate a Laravel specific application.
     * @return void
     */
    private function migrateLaravel()
    {
        if (file_exists($this->appdir . '/artisan')) {
            $this->call($this->conf->binaries->php . ' ' . $this->appdir . '/artisan --force');
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
        $this->call($this->conf->binaries->git . '--git-dir=' . $this->appdir . '/.git --work-tree=' . $this->appdir . ' fetch --all');
        $this->call($this->conf->binaries->git . ' --git-dir = ' . $this->appdir . '/.git --work-tree = ' . $this->appdir . ' reset --hard origin/master');
    }
}
