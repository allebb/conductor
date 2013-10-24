<?php

namespace Conductor\Handlers;

use Conductor\Helpers\ConductorApp;
use Ballen\Executioner;

class LaravelHandler
{

    public function runMigration(ConductorApp $data)
    {
        $migrate = new Executer;
        $migrate->setApplication('/usr/bin/php artisan migrate')
                ->addArgument('--path="' . Config::get('conductor.app_root_dir') . '/' . $data->name . '"');
        $migrate->asExec()->execute();
        echo $migrate->resultAsText();
    }

    public function rollbackMigration(ConductorApp $data)
    {
        $migrate = new Executer;
        $migrate->setApplication('/usr/bin/php artisan migrate:rollback')
                ->addArgument('--path="' . Config::get('conductor.app_root_dir') . '/' . $data->name . '"');
        $migrate->asExec()->execute();
        echo $migrate->resultAsText();
    }

    public function refreshkMigration(ConductorApp $data)
    {
        $migrate = new Executer;
        $migrate->setApplication('/usr/bin/php artisan migrate:refresh')
                ->addArgument('--path="' . Config::get('conductor.app_root_dir') . '/' . $data->name . '/"');
        $migrate->asExec()->execute();
        echo $migrate->resultAsText();
    }

}
