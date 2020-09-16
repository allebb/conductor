<?php

/**
 * Conductor
 *
 * Conductor is a CLI tool to aid provisioning and maintenance of PHP based sites and applications.
 *
 * @author Bobby Allen <ballen@bobbyallen.me>
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/allebb/conductor
 * @link http://bobbyallen.me
 *
 */
class MysqlPdo
{

    protected static $db;

    /**
     * Singleton constuctor
     * @param string $host The database servers address (IP or hostname), defaults to 'localhost'
     * @param string $database The MySQL database name.
     * @param string $user The MySQL database name.
     * @param string $pass The MySQL account password
     */
    private function __construct($host, $database, $user, $pass)
    {
        try {
            self::$db = new PDO('mysql:host=' . $host . ';dbname=' . $database . '', $user, $pass);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
        }
    }

    /**
     * Creates (or returns if already exists) the singleton PDO object.
     * @param string $database The MySQL database name.
     * @param string $user The MySQL account username
     * @param string $pass The MySQL account password
     * @param string $host The database servers address (IP or hostname), defaults to 'localhost'
     * @return PDO
     */
    public static function connect($database, $user, $pass, $host = 'localhost')
    {
        if (!self::$db) {
            new MysqlPdo($host, $database, $user, $pass);
        }
        return self::$db;
    }
}
