#!/usr/bin/env php
<?php
$bindir = dirname(__FILE__);

require_once $bindir . '/inc/CliApplication.php';
require_once $bindir . '/inc/Conductor.php';

$conductor = new Conductor($argv);

$conductor->enforceCli();
$conductor->checkDependencies();

switch ($conductor->commands()[1]) {
    case "list":
        $conductor->writeln('Current applications on this server...');
        break;
    case "new":
        $conductor->writeln('New application added!');
        break;
    case "destroy":
        $conductor->writeln('Destroying the application!');
        break;
    case "update":
        $conductor->writeln('Application has been updated!');
        break;
    case "rollback":
        $conductor->writeln('Rolling back application to last snapshot');
        break;
    case "backup":
        $conductor->writeln('Backing up the application!');
        $conductor->backup();
        break;
    case "restore":
        $conductor->writeln('Restoring an applicaiton!');
        break;
    case "start":
        $conductor->writeln('Attempting to start a Laravel application.');
        break;
    case "stop":
        $conductor->writeln('Attempting to stop a Laravel application.');
        break;
    case "services":
        $conductor->serviceControl($conductor->commands()[2]);
        break;

    default:

        if ($conductor->isFlagSet('v') or $conductor->isFlagSet('version')) {
            $conductor->writeln('Conductor v' . $conductor->version());
            $conductor->endWithSuccess();
        }

        $conductor->writeln();
        $conductor->writeln('Usage: conductor [OTPION]');
        $conductor->writeln('');
        $conductor->writeln('Options:');
        $conductor->writeln('  list              List all currently hosted applications');
        $conductor->writeln('  new {name}        Prepares and deploys a new application');
        $conductor->writeln('  destroy {name}    Removes an application from the server');
        $conductor->writeln('  update {name}     Upgrades an application via. Git');
        $conductor->writeln('  rollback {name}   Rolls back the most recent upgrade');
        $conductor->writeln('  depupdate {name}  Updates all application dependencies (composer update)');
        $conductor->writeln('  backup {name}     Backs up an application and dependent DB\'s');
        $conductor->writeln('  restore {name}    Restores an application and dependent DB\'s');
        $conductor->writeln('  start {name}      Starts a specific application (Laravel Apps only!)');
        $conductor->writeln('  stop {name}       Stops a specific application (Laravel Apps only!)');
        $conductor->writeln('  services start    Starts all dependent Conductor daemons');
        $conductor->writeln('  services stop     Stops all dependent Conductor daemons');
        $conductor->writeln('  services status   Displays the current daemon statuses');
        $conductor->writeln('  services restart  Restarts all dependent Conductor daemons');
        $conductor->writeln('  services reload   Reloads all dependent Conductor daemons');
        $conductor->writeln('  -v                Displays the version number of Conductor');
        $conductor->writeln('  -h                Displays this help screen.');
        $conductor->writeln('');
        $conductor->writeln('Please report bug at: https://github.com/bobsta63/conductor');
        $conductor->writeln();
}
