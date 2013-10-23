<?php

namespace Conductor\Handlers;

use Conductor\Application;
use Conductor\Validators\ApplicationCreationValidator;
use Conductor\Helpers\ConductorApp;

class ApplicationHandler
{

    // Creates Nginx configuration file and reloads the Nginx configuration for changes to take affect.
    public function provisionApplication(ConductorApp $data)
    {
        return true;
    }

    /**
     * Creates a new application row in the database.
     * @param \Conductor\Helpers\ConductorApp $data
     * @return boolean
     */
    public function saveApplication(ConductorApp $data)
    {
        $validator = new ApplicationCreationValidator;
        $result = $validator->validateNewApplication($data);
        if ($result->passes()) {
            $application = new Application();
            $application->name = $data->name;
            $application->fqdn = $data->fqdn;
            $application->git_uri = $data->git_uri;
            $application->mysql_user = $data->mysql_user;
            $application->mysql_pass = $data->mysql_pass;
            $application->mysql_name = $data->mysql_name;
            if ($application->save()) {
                return true;
            } else {
                return false;
            }
        } else {
            dd($result->messages()->all());
        }
    }

    /**
     * Deletes the application record from the database.
     * @param \Conductor\Helpers\ConductorApp $data
     * @return boolean
     */
    public function destroyApplication(ConductorApp $data)
    {
        $application = Application::where('name', $data->name)->first();
        if ($application->count() > 0) {
            $application->delete();
            return true;
        }
        return false;
    }

}
