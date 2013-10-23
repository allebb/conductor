<?php

namespace Conductor\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Support\Facades\Event;
use Conductor\Helpers\ConductorApp;
use Conductor\Application;

class AppDeploy extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'conductor:app-deploy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy a new application';

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

        // We'll first gather some answers so we know what we need to do...
        $name = $this->ask('Application name (eg. myapp): ');
        $fqdn = $this->ask('Hosting FQDN (eg. myapp.mytest.com): ');
        $app_defaults = array(
            'name' => strtolower($name),
            'fqdn' => strtolower($fqdn)
        );
        if ($this->confirm('Would you like a MySQL DB and user generated? [Y/n] ', true)) {
            $app_defaults = array_add($app_defaults, 'mysql_name', strtolower($name));
            $app_defaults = array_add($app_defaults, 'mysql_user', strtolower($name));
            $app_defaults = array_add($app_defaults, 'mysql_pass', str_random(10));
        }
        if ($this->confirm('Is this application to be deployed from Git? [Y/n] ', true)) {
            $clone_uri = $this->ask('Enter the clone URI: ');
            $app_defaults = array_add($app_defaults, 'git_uri', $clone_uri);
        }

        if ($app_defaults['mysql_name']) {
            // We need to create a DB!
            Event::fire('mysql.provision', new ConductorApp($app_defaults));
        }

        $this->info('Provisioning new application.');
        Event::fire('application.create', new ConductorApp($app_defaults));
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array();
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array();
    }

}
