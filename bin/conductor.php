#!/usr/bin/env php
<?php

$bindir = dirname(__FILE__);

require_once $bindir . '/inc/CliApplication.php';
$cli = new CliApplication($argv);

$cli->enforceCli();

if ($cli->isFlagSet('help')) {
    $cli->writeln('This is where we\'d post the help info...');
} else {
    $cli->writeln('No help was required!');
}
$cli->writeln($cli->getOption('git', 'no'));

$shownics = $cli->input('Would you like to see the NIC configuration? [Y/n]', 'y', ['y', 'Y', 'n', 'N']);

if (in_array($shownics, ['Y', 'y'])) {
    $cli->call('ifconfig en1');
}

$reboot = $cli->input('Reboot the server?', 'n', ['n', 'N', 'y', 'Y']);
if(in_array($reboot, ['y', 'Y'])){
    $cli->call('shutdown -r now');
}

//$cli->call('cp -Rf /Applications/Notes.app /Users/ballen/Desktop/');

$cli->endWithSuccess();
