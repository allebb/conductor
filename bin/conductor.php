#!/usr/bin/env php
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
$bindir = dirname(__FILE__);

require_once $bindir . '/inc/CliApplication.php';
require_once $bindir . '/inc/EnvHandler.php';
require_once $bindir . '/inc/Conductor.php';

$conductor = new Conductor($argv);

$commands = $conductor->commands();

if ($conductor->isFlagSet('v') || $conductor->isFlagSet('version')) {
    $conductor->writeln('Conductor v' . $conductor->version());
    $conductor->endWithSuccess();
}

if (isset($commands[1])) {
    switch ($commands[1]) {
        case "__complete":
            $conductor->complete();
            $conductor->endWithSuccess();
            break;
        case "list":
            $conductor->writeln('Applications hosted on this server:');
            $conductor->listApplications();
            $conductor->endWithSuccess();
            break;
        case "versions":
            $conductor->versions();
            $conductor->endWithSuccess();
            break;
        case "stats":
            $conductor->stats();
            $conductor->endWithSuccess();
            break;
        case "test":
            $conductor->testNginxConfiguration();
            $conductor->endWithSuccess();
            break;
        case "geoipdb":
            if (!isset($commands[2])) {
                displayHelp($conductor);
                $conductor->endWithError();
                break;
            }
            $conductor->geoIpDbControl($commands[2]);
            $conductor->endWithSuccess();
            break;
        case "auth":
            $conductor->authControl();
            $conductor->endWithSuccess();
            break;
        case "protect":
            $conductor->protectControl();
            $conductor->endWithSuccess();
            break;
        case "waf":
            $conductor->wafControl();
            $conductor->endWithSuccess();
            break;
        case "new":
            $conductor->newApplication();
            $conductor->endWithSuccess();
            break;
        case "edit":
            $conductor->editApplicationConfig();
            $conductor->endWithSuccess();
            break;
        case "dump":
            $conductor->dumpApplicationConfig();
            $conductor->endWithSuccess();
            break;
        case "load":
            $conductor->loadApplicationConfig();
            $conductor->endWithSuccess();
            break;
        case "enable":
            $conductor->enableApplication();
            $conductor->endWithSuccess();
            break;
        case "disable":
            $conductor->disableApplication();
            $conductor->endWithSuccess();
            break;
        case "cron":
            $conductor->editApplicationCron();
            $conductor->endWithSuccess();
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
            $conductor->endWithSuccess();
            break;
        case "backup":
            $conductor->backup();
            break;
        case "restore":
            $conductor->restore();
            break;
        case "letsencrypt":
            $conductor->generateLetsEncryptCertificate();
            break;
        case "genkey":
            $conductor->createDeploymentKey();
            $conductor->endWithSuccess();
            break;
        case "delkey":
            $conductor->deleteDeploymentKey();
            $conductor->endWithSuccess();
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
            if (!isset($commands[2])) {
                displayHelp($conductor);
                $conductor->endWithError();
                break;
            }
            $conductor->serviceControl($commands[2]);
            $conductor->endWithSuccess();
            break;
        case "ban":
            if (!isset($commands[2])) {
                displayHelp($conductor);
                $conductor->endWithError();
                break;
            }
            $conductor->banControl($commands[2]);
            $conductor->endWithSuccess();
            break;
        case "unban":
            if (!isset($commands[2])) {
                displayHelp($conductor);
                $conductor->endWithError();
                break;
            }
            $conductor->unbanIpAddress($commands[2]);
            $conductor->endWithSuccess();
            break;

        default:
            displayHelp($conductor);
            $conductor->endWithError();
    }
} else {
    displayHelp($conductor);
    $conductor->endWithError();
}

/**
 * The 'help' screen text.
 */
function displayHelp($conductor)
{
    $conductor->writeln();
    $conductor->writeln('Usage: conductor [OPTION]');
    $conductor->writeln();
    $conductor->writeln('Options:');
    $conductor->writeln('  list                List all currently hosted applications');
    $conductor->writeln('  versions            Display installed component versions');
    $conductor->writeln('  stats [--format=json]');
    $conductor->writeln('                      Display system, Nginx and IP address details');
    $conductor->writeln('  test                Test the Nginx configuration');
    $conductor->writeln('  geoipdb update      Download/update the GeoIP country database');
    $conductor->writeln('  auth {name} --enable|--disable');
    $conductor->writeln('  auth {name} set {username} {password}');
    $conductor->writeln('  auth {name} delete {username}');
    $conductor->writeln('  protect {name} --enable|--disable');
    $conductor->writeln('                      Enable or disable optional security logging');
    $conductor->writeln('  waf {name} [--enable|--disable]');
    $conductor->writeln('                      Edit, enable or disable the application WAF include');
    $conductor->writeln('  new {name}          Prepares and deploys a new application');
    $conductor->writeln('  edit {name}         Open a text editor to update the vhost config.');
    $conductor->writeln('  dump {name} [--waf] Write the vhost or WAF config to STDOUT.');
    $conductor->writeln('  load {name} [--waf] Read the vhost or WAF config from STDIN.');
    $conductor->writeln('  enable {name}       Enables an application vhost config.');
    $conductor->writeln('  disable {name}      Disables an application vhost config.');
    $conductor->writeln('  cron {name}         Open a text editor to update the application crontab.');
    $conductor->writeln('  letsencrypt {name}  Provisions (or renews) a LetsEncrypt SSL cert.');
    $conductor->writeln('  destroy {name}      Removes an application from the server');
    $conductor->writeln('  update {name}       Upgrades an application via. Git');
    $conductor->writeln('  rollback {name}     Rolls back the most recent upgrade');
    $conductor->writeln('  envars {name}       Add, amend or delete environment variables');
    $conductor->writeln('  backup {name}       Backs up an application and dependent DB\'s');
    $conductor->writeln('  restore {name}      Restores an application and dependent DB\'s');
    $conductor->writeln('  start {name}        Starts a specific application (Laravel Apps only!)');
    $conductor->writeln('  stop {name}         Stops a specific application (Laravel Apps only!)');
    $conductor->writeln('  ban list            Lists all IP addresses banned by Fail2Ban');
    $conductor->writeln('  ban purge           Clears all IP addresses banned by Fail2Ban');
    $conductor->writeln('  ban {ip_address}    Manually bans an IP address');
    $conductor->writeln('  unban {ip_address}  Unbans an IP address from all Fail2Ban jails');
    $conductor->writeln();
    $conductor->writeln('  genkey {name}       Generates an SSH deployment key for an application');
    $conductor->writeln('  delkey {name}       Deletes the SSH deployment key for an application.');
    $conductor->writeln('');
    $conductor->writeln('  services start      Starts all dependent Conductor daemons');
    $conductor->writeln('  services stop       Stops all dependent Conductor daemons');
    $conductor->writeln('  services status     Displays the current daemon statuses');
    $conductor->writeln('  services restart    Restarts all dependent Conductor daemons');
    $conductor->writeln('  services reload     Reloads all dependent Conductor daemons');
    $conductor->writeln();
    $conductor->writeln('  -v | --version      Displays the version number of Conductor');
    $conductor->writeln('  -h                  Displays this help screen.');
    $conductor->writeln();
    $conductor->writeln('Please report bug at: https://github.com/allebb/conductor');
    $conductor->writeln();
}
