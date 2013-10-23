<?php

namespace Conductor\Helpers;

use Conductor\Application;

class ConductorApp
{

    public $name = null;
    public $fqdn = null;
    public $git_uri = null;
    public $mysql_name = null;
    public $mysql_user = null;
    public $mysql_pass = null;

    /**
     * Used to manually set properites for the object.
     * @param array $properties
     */
    public function set(array $properties)
    {
        foreach ($properties as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Loads object properties from the database.
     * @param string $name The name of the project.
     */
    public function load($name)
    {
        $application = Application::where('name', $name)->first();
        if (isset($application)) {
            $this->name = $application->name;
            $this->fqdn = $application->fqdn;
            $this->git_uri = $application->git_uri;
            $this->mysql_name = $application->mysql_name;
            $this->mysql_user = $application->mysql_user;
            $this->mysql_pass = $application->mysql_pass;
        } else {
            echo "No application named '" . $name . "' was found!";
        }
    }

}
