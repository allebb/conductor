<?php

namespace Conductor\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Conductor\Application;

class AppList extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'conductor:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lists all deployed applications';

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
        $apps = Application::all();
        $this->info("Live applications:");
        if ($apps->count() > 0) {
            foreach ($apps as $app) {
                $this->info("  > " . $app->name . " - [ " . $app->fqdn . "]");
            }
        } else {
            $this->error('No apps have been yet deployed!');
        }
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
