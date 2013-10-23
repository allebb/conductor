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
                echo "" . PHP_EOL;
                echo "Database successfully created!\n" . PHP_EOL;
                echo "" . PHP_EOL;
                echo " Database name:" . $data->mysql_name . PHP_EOL;
                echo " Username: " . $data->mysql_user . PHP_EOL;
                echo " Password: " . $data->mysql_pass . PHP_EOL;
                echo "" . PHP_EOL;
                echo "Please ensure that your production database config matches these values!" . PHP_EOL;
                echo "" . PHP_EOL;
            } else {
                echo "Oh dang! We couldn't create the database!" . PHP_EOL;
            }
            DB::connection('mysql')->statement("GRANT ALL PRIVILEGES ON `" . $data->mysql_name . "`.* TO `" . $data->mysql_user . "`@`localhost`;");
            DB::connection('mysql')->statement("FLUSH PRIVILEGES;");
        } else {
            echo "The user could not be created on the database server!" . PHP_EOL;
        }
    }

    /**
     * Deletes an application database and corresponding user account.
     * @param \Conductor\Helpers\ConductorApp $data
     */
    public function destroyDatabase(ConductorApp $data)
    {
        if ($data->mysql_name != null) {
            if (DB::connection('mysql')->statement("DROP " . $data->mysql_name . ";")) {
                DB::connection('mysql')->statement("DROP USER `" . $data->mysql_user . "`@`localhost`;");
                DB::connection('mysql')->statement("FLUSH PRIVILEGES;");
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

}
