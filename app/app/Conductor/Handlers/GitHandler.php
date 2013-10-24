<?php

namespace Conductor\Handlers;

use Conductor\Helpers\ConductorApp;
use Illuminate\Support\Facades\Config;
use Ballen\Executioner\Executer;

class GitHandler
{

    public function cloneRepository(ConductorApp $project)
    {
        // As we're pulling from git we should first remove the created directory as Git clone will create it!
        @rmdir(Config::get('conductor.app_root_dir') . '/' . $project->name);
        // Now we'll clone from the repository!
        $clone = new Executer;
        $clone->setApplication('/usr/bin/git clone')
                ->addArgument($project->git_uri)
                ->addArgument(Config::get('conductor.app_root_dir') . '/' . $project->name);
        $clone->asExec()->execute();
        echo $clone->resultAsText();
        echo "Clone complete!" . PHP_EOL;
    }

    public function pullRepository(ConductorApp $project)
    {
        $clone = new Executer;
        $clone->setApplication('/usr/bin/git pull')
                ->addArgument('--git-url=' . Config::get('conductor.app_root_dir') . '/' . $project->name);
        $clone->asExec()->execute();
        echo $clone->resultAsText();
        echo "Git 'pull' complete!" . PHP_EOL;
    }

}
