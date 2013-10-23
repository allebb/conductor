<?php

namespace Conductor\Handlers;

use Conductor\Helpers\ConductorApp;
use Illuminate\Support\Facades\Config;
use Ballen\Executioner\Executer;

class GitHandler
{

    public function cloneRepository(ConductorApp $project)
    {
        $clone = new Executer;
        $clone->setApplication('/usr/bin/git clone')
                ->addArgument($project->git_uri)
                ->addArgument(Config::get('conductor.app_root_dir') . '/' . $project->name);
        $clone->asExec()->execute();
        //echo $clone->resultAsText();
    }

    public function pullRepository(ConductorApp $project)
    {

    }

}
