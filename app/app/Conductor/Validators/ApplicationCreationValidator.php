<?php

namespace Conductor\Validators;

use Illuminate\Support\Facades\Validator;

class ApplicationCreationValidator
{

    /**
     * Valiadtes new application settings.
     * @param array $data
     */
    public function validateNewApplication($data)
    {
        $validator = Validator::make(
                        array(
                    'name' => $data['name'],
                    'fqdn' => $data['fqdn'],
                    'git_enabled' => $data['git']['enabled'],
                    'git uri' => $data['git']['uri'],
                    'mysql_db_name' => $data['mysql']['name'],
                    'mysql_user' => $data['mysql']['user'],
                    'mysql_pass' => $data['mysql']['pass'],
                        ), array(
                    'name' => array('required', 'min:5', 'unique:application,name'),
                    'fqdn' => array('required', 'unique:application,fqdn'),
                    'gitenabled' => array('required'),
                        //'git uri' => array() # We validate this with ->sometimes() instead.
                        //'mysql db name' => array(), # We validate this with ->sometimes() instead.
                        //'mysql user' => array(), # We validate this with ->sometimes() instead.
                        //'mysql pass' => array(), # We validate this with ->sometimes() instead.
                        )
        );
        $validator->sometimes('git_uri', 'required', function($input) {
            return $input->git_enabled = true;
        });
        $validator->sometimes(array('mysql_user', 'mysql_pass'), 'required', function($input) {
            return $input->mysql_db_name = true;
        });
        return $validator;
    }

}
