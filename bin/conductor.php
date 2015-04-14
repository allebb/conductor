#!/usr/bin/env php
<?php
$bindir = dirname(__FILE__);

require_once $bindir . '/inc/CliApplication.php';
require_once $bindir . '/inc/EnvHandler.php';
require_once $bindir . '/inc/Conductor.php';

$conductor = new Conductor($argv);

$commands = $conductor->commands();

if ($conductor->isFlagSet('v')) {
    $conductor->writeln('Conductor v' . $conductor->version());
    $conductor->endWithSuccess();
}

if (isset($commands[1])) {
    switch ($commands[1]) {
        case "list":
            $conductor->writeln('Applications hosted on this server:');
            $conductor->listApplications();
            break;
        case "new":
            $conductor->newApplication();
            break;
        case "destroy":
            $conductor->destroy();
            break;
        case "update":
            $conductor->updateApplication();
            break;
        case "rollback":
            $conductor->rollback();
            break;
        case "envars":
            $conductor->updateEnvVars();
            break;
        case "backup":
            $conductor->backup();
            break;
        case "restore":
            $conductor->restore();
            break;
        case "start":
            $conductor->startLaravelApplication();
            $conductor->endWithSuccess();
            break;
        case "stop":
            $conductor->stopLaravelApplication();
            $conductor->endWithSuccess();
            break;
        case "services":
            $conductor->serviceControl($commands[2]);
            $conductor->endWithSuccess();
            break;

        default:
            displayHelp($conductor);
    }
} else {
    displayHelp($conductor);
}

/**
 * The 'help' screen text.
 */
function displayHelp($conductor)
{
    $conductor->writeln();
    $conductor->writeln('Usage: conductor [OTPION]');
    $conductor->writeln();
    $conductor->writeln('Options:');
    $conductor->writeln('  list              List all currently hosted applications');
    $conductor->writeln('  new {name}        Prepares and deploys a new application');
    $conductor->writeln('  destroy {name}    Removes an application from the server');
    $conductor->writeln('  update {name}     Upgrades an application via. Git');
    $conductor->writeln('  rollback {name}   Rolls back the most recent upgrade');
    $conductor->writeln('  envars {name}     Add, ammend or delete environment variables');
    $conductor->writeln('  backup {name}     Backs up an application and dependent DB\'s');
    $conductor->writeln('  restore {name}    Restores an application and dependent DB\'s');
    $conductor->writeln('  start {name}      Starts a specific application (Laravel Apps only!)');
    $conductor->writeln('  stop {name}       Stops a specific application (Laravel Apps only!)');
    $conductor->writeln('  services start    Starts all dependent Conductor daemons');
    $conductor->writeln('  services stop     Stops all dependent Conductor daemons');
    $conductor->writeln('  services status   Displays the current daemon statuses');
    $conductor->writeln('  services restart  Restarts all dependent Conductor daemons');
    $conductor->writeln('  services reload   Reloads all dependent Conductor daemons');
    $conductor->writeln();
    $conductor->writeln('  -v                Displays the version number of Conductor');
    $conductor->writeln('  -h                Displays this help screen.');
    $conductor->writeln();
    $conductor->writeln('Please report bug at: https://github.com/bobsta63/conductor');
    $conductor->writeln();
}
