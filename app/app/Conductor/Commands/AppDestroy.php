<?php

namespace Conductor\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Support\Facades\Config;
use Conductor\Helpers\ConductorApp;
use Illuminate\Support\Facades\Event;

class AppDestroy extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'conductor:app-destroy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes an application and database(s) fromm the server';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $appname = strtolower($this->argument('appname'));
        $application = new ConductorApp();
        $application->load($appname);
        // Lets go and delete the nginx configuration file for the virtual host!
        if (@unlink(Config::get('conductor.vhconf_root_dir') . '/' . $appname . '.conf')) {
            if (@rmdir(Config::get('conductor.app_root_dir') . '/' . $appname)) {
                if (!Event::fire('mysql.destroy', $application))
                    echo "MySQL database and user could not be removed!" . PHP_EOL;
                // Now we remove the applicaiton from the DB
                Event::fire('application.destroy', $application);
            } else {
                echo "Unable to delete the application hosting directory." . PHP_EOL;
            }
        } else {
            echo "Unable to delete Nginx configuraiton!" . PHP_EOL;
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('appname', InputArgument::REQUIRED, 'Application name'),
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
                //array('app_name', null, InputOption::VALUE_REQUIRED, 'Application name', null),
        );
    }

}
