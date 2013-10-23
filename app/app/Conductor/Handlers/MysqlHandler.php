<?php

namespace Conductor\Handlers;

use Conductor\Helpers\ConductorApp;
use Illuminate\Support\Facades\DB;

class MysqlHandler
{

    /**
     * Provisions a new MySQL database for the application.
     * @param \Conductor\Helpers\ConductorApp $data
     */
    public function provisionDatabase(ConductorApp $data)
    {
        if (DB::connection('mysql')->statement("CREATE USER `" . $data->mysql_user . "`@`localhost` IDENTIFIED BY '" . $data->mysql_pass . "';")) {
            if (DB::connection('mysql')->statement("CREATE SCHEMA " . $data->mysql_name . ";")) {
                echo "";
                echo "Database successfully created!\n";
                echo "";
                echo " Database name:" . $data->mysql_name;
                echo " Username: " . $data->mysql_user;
                echo " Password: " . $data->mysql_pass;
                echo "";
                echo "Please ensure that your production database config matches these values!";
                echo "";
            } else {
                echo "Oh dang! We couldn't create the database!\n";
            }
            DB::connection('mysql')->statement("GRANT ALL PRIVILEGES ON `" . $data->mysql_name . "`.* TO `" . $data->mysql_user . "`@`localhost`;");
            DB::connection('mysql')->statement("FLUSH PRIVILEGES;");
        } else {
            echo "The user could not be created on the database server!";
        }
    }

    /**
     * Deletes an application database and corresponding user account.
     * @param \Conductor\Helpers\ConductorApp $data
     */
    public function destroyDatabase(ConductorApp $data)
    {
        if (DB::connection('mysql')->statement("DROP " . $data->mysql_name . ";")) {
            DB::connection('mysql')->statement("DROP USER `" . $data->mysql_user . "`@`localhost`;");
            DB::connection('mysql')->statement("FLUSH PRIVILEGES;");
            return true;
        } else {
            return false;
        }
    }

}
