<?php

namespace Conductor\Handlers;

use Conductor\Application;
use Conductor\Validators\ApplicationCreationValidator;

class ApplicationHandler
{

    // Creates Nginx configuration file and reloads the Nginx configuration for changes to take affect.
    public function provisionApplication($data)
    {
        return true;
    }

    // Save the application settings t othe database.
    public function saveApplication($data)
    {
        $validator = new ApplicationCreationValidator;
        if ($validator->passes()) {
            $application = new Application();
            $application->name = $data['name'];
            $application->fqdn = $data['fqdn'];
            $application->mysql_name = $data['db']['name'];
            $application->mysql_user = $data['db']['user'];
            $application->mysql_pass = $data['db']['pass'];
            $application->git_uri = $data['git']['uri'];
            if ($application->save()) {
                return true;
            } else {
                return false;
            }
        }
    }

}
