<?php

namespace Conductor\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Support\Facades\Event;

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

        // First we'll set a few defaults to avoid horrible 'not declared' errors...
        $mysql_generate = false;
        $git_deploy = false;
        $clone_uri = null;
        $mysql_user = null;
        $mysql_db = null;
        $mysql_pass = null;

        // We'll first gather some answers so we know what we need to do...
        $name = $this->ask('Application name (eg. myapp): ');
        $fqdn = $this->ask('Hosting FQDN (eg. myapp.mytest.com): ');
        if ($this->confirm('Would you like a MySQL DB and user generated? [Y/n] ', true)) {
            $mysql_generate = true;
        }
        if ($this->confirm('Is this application to be deployed from Git? [Y/n] ', true)) {
            $git_deploy = true;
            $clone_uri = $this->ask('Enter the clone URI: ');
        }

        // We'll now collate this infomation so we can easily pass it to the event handlers to be used as requird.
        $app_details = array(
            'name' => $name,
            'fqdn' => strtolower($fqdn),
            'git' => array(
                'enabled' => $git_deploy,
                'uri' => $clone_uri,
            ),
            'db' => array(
                'created' => $mysql_generate,
                'user' => $mysql_user,
                'pass' => $mysql_pass,
                'name' => $mysql_db,
            ),
        );

        if ($mysql_generate) {
            // A new MySQL User and DB should be created!
            Event::fire('mysql.provision', $app_details);
        }

        if ($git_deploy) {
            // We have to deploy from Git!
            Event::fire('git.deploy', $app_details);
        }



        Event::fire('application.create', $app_details);
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
