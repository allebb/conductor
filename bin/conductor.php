#!/usr/bin/env php
<?php
$bindir = dirname(__FILE__);

require_once $bindir . '/inc/CliApplication.php';
require_once $bindir . '/inc/Conductor.php';

$conductor = new Conductor($argv);

$conductor->enforceCli();
$conductor->checkDependencies();

switch ($conductor->commands()[1]) {
    case "new":
        $conductor->writeln('New application added!');
        break;
    case "backup":
        $conductor->writeln('Backing up the application!');
        break;
    case "destroy":
        $conductor->writeln('Destroying the application!');
        break;
    default:
        $conductor->writeln();
        $conductor->writeln('Conductor help file...');
        $conductor->writeln();
}
