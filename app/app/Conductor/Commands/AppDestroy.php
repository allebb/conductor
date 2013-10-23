<?php

namespace Conductor\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Conductor\Helpers\ConductorApp;

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
        $application = new ConductorApp();
        $application->load($this->argument('appname'));
        echo "Destoyed " . $application->mysql_name . "";
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
