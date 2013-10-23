<?php

namespace Conductor\Helpers;

class ConductorApp
{

    public $name = null;
    public $fqdn = null;
    public $git_uri = null;
    public $mysql_name = null;
    public $mysql_user = null;
    public $mysql_pass = null;

    public function __construct(array $properties)
    {
        foreach ($properties as $key => $value) {
            $this->$key = $value;
        }
    }

}
