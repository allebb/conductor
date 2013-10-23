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

    // Save the application settings t othe database.
    public function saveApplication(ConductorApp $data)
    {
        $validator = new ApplicationCreationValidator;
        $result = $validator->validateNewApplication($data);
        dd((array) $data);
        if ($result->passes()) {
            $application = new Application();
            if ($application->save((array) $data)) {
                return true;
            } else {
                return false;
            }
        } else {
            dd($result->messages()->all());
        }
    }

}
