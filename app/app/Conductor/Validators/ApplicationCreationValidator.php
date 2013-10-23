<?php

namespace Conductor\Validators;

use Illuminate\Support\Facades\Validator;
use Conductor\Helpers\ConductorApp;

class ApplicationCreationValidator
{

    /**
     * Valiadtes new application settings.
     * @param array $data
     */
    public function validateNewApplication(ConductorApp $data)
    {
        $validator = Validator::make(
                        array(
                    'name' => $data->name,
                    'fqdn' => $data->fqdn,
                    'git uri' => $data->git_uri,
                    'mysql_ame' => $data->mysql_name,
                    'mysql_user' => $data->mysql_user,
                    'mysql_pass' => $data->mysql_pass,
                        ), array(
                    'name' => array('required', 'min:5', 'unique:application,name'),
                    'fqdn' => array('required', 'unique:application,fqdn'),
                        )
        );
        $validator->sometimes(array('mysql_user', 'mysql_pass'), 'required', function($input) {
            return !empty($input->mysql_name); // If a MySQL database name 'mysql_name' has been added, we'll additonally require the 'mysql_user' and 'mysql_pass'.
        });
        return $validator;
    }

}
