<?php

namespace Tests;

use Conductor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConductorTest extends TestCase
{
    /**
     * Conductor's constructor requires root privileges, a live /etc/conductor.conf
     * and a MySQL connection, so pure/stateless methods are exercised on an instance
     * built without invoking the constructor.
     */
    private function makeConductor(): Conductor
    {
        return (new ReflectionClass(Conductor::class))->newInstanceWithoutConstructor();
    }

    private function makeConductorWithConfig(object $config): Conductor
    {
        $conductor = $this->makeConductor();
        $property = (new ReflectionClass(Conductor::class))->getProperty('conf');
        $property->setValue($conductor, $config);

        return $conductor;
    }

    private function nginxQuotedRegexForPcre(string $pattern): string
    {
        return str_replace(['\\\\', '\\"'], ['\\', '"'], $pattern);
    }

    private function assertNginxRegexCompiles(string $pattern): void
    {
        $pattern = $this->nginxQuotedRegexForPcre($pattern);
        $delimiter = '~';
        $regex = $delimiter . str_replace($delimiter, '\\' . $delimiter, $pattern) . $delimiter . 'i';

        $this->assertNotFalse(@preg_match($regex, ''), preg_last_error_msg() . ': ' . $pattern);
    }

    public function testVersionMatchesTheVersionConstant(): void
    {
        $this->assertSame(Conductor::CONDUCTOR_VERSION, $this->makeConductor()->version());
    }

    public function testCheckDependenciesPassesWhenRequiredExtensionsAreLoaded(): void
    {
        // The test environment (and composer.json) guarantees PDO, pdo_mysql,
        // posix and json are present, so this should return without exiting.
        $this->makeConductor()->checkDependencies();
        $this->addToAssertionCount(1);
    }

    public function testNewApplicationsGenerateDeploymentKeysWithoutPrompting(): void
    {
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $this->assertStringContainsString('$generate_keys = self::OPTION_YES;', $source);
        $this->assertStringNotContainsString('Create an SSH deployment key pair now?', $source);
        $this->assertStringContainsString('Generating a deployment (SSH) key pair...', $source);
    }

    public function testDestroyPromptsBeforeDeletingDeploymentKeys(): void
    {
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $this->assertStringContainsString('Delete the SSH deployment key pair for this application too?', $source);
        $this->assertStringContainsString("self::OPTION_YES,\n            [self::OPTION_YES, self::OPTION_NO]", $source);
        $this->assertStringContainsString('$this->promptDeleteDeploymentKeyFiles();', $source);
        $this->assertStringNotContainsString('otherwise you can delete them too by running:', $source);
        $this->assertStringNotContainsString('conductor delkey \' . $this->appname', $source);
    }

    public function testShowDeploymentKeyPrintsExistingPublicKey(): void
    {
        $root = sys_get_temp_dir() . '/conductor-showkey-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/myapp.deploykey.pub', "ssh-ed25519 AAAATEST deploy@myapp\n");

        $conductor = new class extends Conductor {
            public array $lines = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $root,
                'deploykeys' => $root,
            ],
        ]);

        $conductor->showDeploymentKey();

        $output = implode(PHP_EOL, $conductor->lines);
        $this->assertStringContainsString('ssh-ed25519 AAAATEST deploy@myapp', $output);
        $this->assertStringContainsString('Copy and paste the above public key content', $output);

        @unlink($root . '/myapp.deploykey.pub');
        @rmdir($root);
    }

    public function testDeploymentKeyOutputMentionsReadOnlyDeploymentKeyAndConductorGitignore(): void
    {
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $this->assertStringContainsString('as a read-only deployment key', $source);
        $this->assertStringContainsString('adding /.conductor to your repository .gitignore before cloning', $source);
    }

    public function testGitDeployKeepsCloneDirectoryEmptyBeforeClone(): void
    {
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $clone_position = strpos($source, '$this->conf->binaries->git . \' clone --branch \'');
        $appdir_chown_position = strpos($source, '$this->call(\'chown -R \' . $this->conf->permissions->webuser . \':\' . $this->conf->permissions->webgroup . \' \' . $this->appdir);');
        $env_position = strpos($source, '/usr/bin/conductor envars', $clone_position);
        $error_pages_position = strpos($source, '$this->createApplicationErrorPage($status_code, $error_page_root);', $clone_position);

        $this->assertNotFalse($clone_position);
        $this->assertNotFalse($appdir_chown_position);
        $this->assertNotFalse($env_position);
        $this->assertNotFalse($error_pages_position);
        $this->assertLessThan($clone_position, $appdir_chown_position);
        $this->assertGreaterThan($clone_position, $env_position);
        $this->assertGreaterThan($clone_position, $error_pages_position);
        $this->assertStringContainsString('if (!$this->isDirectoryEmpty($this->appdir))', $source);
        $this->assertStringNotContainsString('$this->call(\'rm -Rf \' . $this->appname);', $source);
        $this->assertStringContainsString('Git branch [main]:', $source);
        $this->assertStringContainsString('$gitbranch = trim($this->getOption(\'git-branch\', \'main\'));', $source);
        $this->assertStringContainsString('$gitrepo = trim($this->input(\'Git repository URI (eg. git@github.com:user/repo.git):\'));', $source);
        $this->assertStringContainsString('$gitbranch = trim($this->input(\'Git branch [main]: \'));', $source);
        $this->assertStringContainsString('$gitbranch = \'main\';', $source);
        $this->assertStringContainsString('git . \' clone --branch \' . escapeshellarg($gitbranch)', $source);
        $this->assertStringContainsString('Git clone failed; check the repository URI and branch, then try again.', $source);
        $this->assertStringNotContainsString('Git checkout failed; aborting application deployment.', $source);
    }

    public function testGitCommandsUseDeploymentKeyAsWebUser(): void
    {
        $conductor = new class extends Conductor {
            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => '/var/conductor/applications',
                'deploykeys' => '/var/www/.ssh',
            ],
            'permissions' => (object) [
                'webuser' => 'www-data',
            ],
        ]);

        $reflection->getMethod('setAppName')->invoke($conductor);
        $command = $reflection->getMethod('gitWithDeploymentKey')->invoke(
            $conductor,
            'git clone ' . escapeshellarg('git@github.com:user/repo.git') . ' .',
            '/var/conductor/applications/myapp'
        );

        $this->assertStringContainsString("sudo -u 'www-data' ssh-agent bash -c", $command);
        $this->assertStringContainsString("cd '\\''/var/conductor/applications/myapp'\\''; ssh-add '\\''/var/www/.ssh/myapp.deploykey'\\''; git clone '\\''git@github.com:user/repo.git'\\'' .", $command);

        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');
        $this->assertStringContainsString('reset --hard @{u}', $source);
        $this->assertStringNotContainsString('current_branch=$(', $source);
        $this->assertStringNotContainsString('origin/master', $source);
    }

    public function testComposerCommandsRunAsWebUser(): void
    {
        $conductor = new class extends Conductor {
            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => '/var/conductor/applications',
            ],
            'permissions' => (object) [
                'webuser' => 'www-data',
            ],
            'binaries' => (object) [
                'composer' => '/usr/bin/composer',
            ],
        ]);

        $reflection->getMethod('setAppName')->invoke($conductor);
        $command = $reflection->getMethod('composerForApplication')->invoke(
            $conductor,
            'install --no-dev --optimize-autoloader'
        );

        $this->assertSame(
            "sudo -u 'www-data' /usr/bin/composer install --no-dev --optimize-autoloader --working-dir='/var/conductor/applications/myapp'",
            $command
        );
    }

    public function testLaravelDetectionUsesArtisanFileOnly(): void
    {
        $root = sys_get_temp_dir() . '/conductor-laravel-detect-' . uniqid();
        mkdir($root);
        mkdir($root . '/myapp');

        $conductor = new class extends Conductor {
            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $root,
            ],
        ]);
        $reflection->getMethod('setAppName')->invoke($conductor);

        $is_laravel = $reflection->getMethod('isLaravelApplication');
        $this->assertFalse($is_laravel->invoke($conductor));

        file_put_contents($root . '/myapp/artisan', '#!/usr/bin/env php');

        $this->assertTrue($is_laravel->invoke($conductor));

        @unlink($root . '/myapp/artisan');
        @rmdir($root . '/myapp');
        @rmdir($root);
    }

    public function testLaravelMigrationSkipsWhenEnvFileIsMissing(): void
    {
        $root = sys_get_temp_dir() . '/conductor-laravel-migrate-' . uniqid();
        mkdir($root);
        mkdir($root . '/myapp');
        file_put_contents($root . '/myapp/artisan', '#!/usr/bin/env php');

        $conductor = new class extends Conductor {
            public array $calls = [];
            public array $lines = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $root,
            ],
            'permissions' => (object) [
                'webuser' => 'www-data',
            ],
            'binaries' => (object) [
                'php' => '/usr/bin/php',
                'composer' => '/usr/bin/composer',
            ],
        ]);
        $reflection->getMethod('setAppName')->invoke($conductor);
        $reflection->getMethod('migrateLaravel')->invoke($conductor, 'production');

        $this->assertSame([], $conductor->calls);
        $this->assertContains('No .env file found, skipping migrations...', $conductor->lines);

        @unlink($root . '/myapp/artisan');
        @rmdir($root . '/myapp');
        @rmdir($root);
    }

    public function testStartLaravelApplicationSkipsNonLaravelApplications(): void
    {
        $root = sys_get_temp_dir() . '/conductor-laravel-start-' . uniqid();
        mkdir($root);
        mkdir($root . '/myapp');

        $conductor = new class extends Conductor {
            public array $calls = [];
            public array $lines = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $root,
            ],
            'binaries' => (object) [
                'php' => '/usr/bin/php',
            ],
        ]);

        $conductor->startLaravelApplication();

        $this->assertSame([], $conductor->calls);
        $this->assertContains('The application does not appear to be a Laravel-based application, skipping this operation!', $conductor->lines);

        @rmdir($root . '/myapp');
        @rmdir($root);
    }

    public function testBanListDebugShowsRawFail2BanOutput(): void
    {
        $conductor = new class extends Conductor {
            public array $lines = [];

            public function __construct()
            {
            }

            public function runFail2BanClient($arguments, &$output)
            {
                $command = implode(' ', $arguments);

                if ($command == 'status') {
                    $output = ['Status', '`- Jail list: conductor-nginx-4xx'];
                    return 0;
                }

                if ($command == 'get conductor-nginx-4xx banip --with-time') {
                    $output = ['172.25.87.140 2026-07-17 17:00:00 +0000 1800'];
                    return 0;
                }

                if ($command == 'get conductor-nginx-4xx bantime') {
                    $output = ['1800'];
                    return 0;
                }

                $output = [];
                return 1;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getMethod('listBannedIps')->invoke($conductor, true);

        $output = implode(PHP_EOL, $conductor->lines);
        $this->assertStringContainsString('Raw Fail2Ban banip output for conductor-nginx-4xx:', $output);
        $this->assertStringContainsString('172.25.87.140 2026-07-17 17:00:00 +0000 1800', $output);
        $this->assertStringContainsString('172.25.87.140', $output);
        $this->assertStringContainsString('30 minute(s)', $output);
    }

    public function testApplicationConductorConfigWritesRestoreMetadataAndEnvVars(): void
    {
        $root = sys_get_temp_dir() . '/conductor-app-config-' . uniqid();
        $apps = $root . '/apps';
        $configs = $root . '/configs';
        mkdir($apps, 0755, true);
        mkdir($configs, 0755, true);
        mkdir($apps . '/myapp', 0755, true);
        file_put_contents($configs . '/myapp_envars.json', json_encode([
            'APP_ENV' => 'staging',
            'QUEUE_CONNECTION' => 'redis',
        ]));

        $conductor = new class extends Conductor {
            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $apps,
                'appconfs' => $configs,
            ],
            'mysql' => (object) [
                'host' => '127.0.0.1',
            ],
        ]);

        $set_app_name = $reflection->getMethod('setAppName');
        $set_app_name->invoke($conductor);

        $write_config = $reflection->getMethod('writeApplicationConductorConfig');
        $write_config->invoke(
            $conductor,
            'staging',
            $apps . '/myapp',
            'example.com www.example.com',
            Conductor::OPTION_YES,
            'secret'
        );

        $config = json_decode(file_get_contents($apps . '/myapp/.conductor/config.json'), true);
        $this->assertSame('myapp', $config['appname']);
        $this->assertSame('staging', $config['environment_type']);
        $this->assertSame($apps . '/myapp', $config['root_path']);
        $this->assertSame('db_myapp', $config['mysql_db_name']);
        $this->assertSame('myapp', $config['mysql_db_user']);
        $this->assertSame('secret', $config['mysql_db_pass']);
        $this->assertSame('127.0.0.1', $config['mysql_db_host']);
        $this->assertSame('example.com www.example.com', $config['fqdn']);
        $this->assertSame('redis', $config['env']['QUEUE_CONNECTION']);

        @unlink($apps . '/myapp/.conductor/config.json');
        @rmdir($apps . '/myapp/.conductor');
        @unlink($configs . '/myapp_envars.json');
        @rmdir($apps . '/myapp');
        @rmdir($apps);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testVersionsDisplaysDetectedVersionsAndNAForMissingBinaries(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'conductor-certbot-');
        file_put_contents($binary, "#!/bin/sh\necho 'certbot 1.2.3'\n");
        chmod($binary, 0755);

        $conductor = new class extends Conductor {
            public array $lines = [];

            public function __construct()
            {
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        $property = (new ReflectionClass(Conductor::class))->getProperty('conf');
        $property->setValue($conductor, (object) [
            'binaries' => (object) [
                'certbot' => $binary,
                'mysql' => '/no/such/mysql',
            ],
        ]);

        $conductor->versions();
        $output = implode(PHP_EOL, $conductor->lines);
        @unlink($binary);

        $this->assertStringContainsString('Component', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertMatchesRegularExpression('/CertBot\s+1\.2\.3/', $output);
        $this->assertMatchesRegularExpression('/MySQL\s+N\/A/', $output);
        $this->assertMatchesRegularExpression('/nftables\s+N\/A/', $output);
        $this->assertStringNotContainsString('nftable ', $output);
        $this->assertMatchesRegularExpression('/Crowdsec\s+N\/A/', $output);
    }

    public function testVersionsCanReturnJson(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'conductor-certbot-json-');
        file_put_contents($binary, "#!/bin/sh\necho 'certbot 1.2.3'\n");
        chmod($binary, 0755);

        $conductor = new class extends Conductor {
            public array $lines = [];

            public function __construct()
            {
            }

            public function getOption($name, $default = false)
            {
                return $name == 'format' ? 'json' : $default;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'binaries' => (object) [
                'certbot' => $binary,
                'mysql' => '/no/such/mysql',
            ],
        ]);

        $conductor->versions();
        $payload = json_decode(implode(PHP_EOL, $conductor->lines), true, 512, JSON_THROW_ON_ERROR);
        @unlink($binary);

        $this->assertSame('1.2.3', $payload['CertBot']);
        $this->assertSame('N/A', $payload['MySQL']);
        $this->assertSame('N/A', $payload['Crowdsec']);
        $this->assertArrayNotHasKey('Component', $payload);
    }

    public function testListStreamsShowsEnabledAndDisabledConfigurations(): void
    {
        $root = sys_get_temp_dir() . '/conductor-list-streams-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/alpha.conf', "stream {}\n");
        file_put_contents($root . '/bravo.disabled', "stream {}\n");
        file_put_contents($root . '/example.conf.example', "stream {}\n");
        file_put_contents($root . '/notes.txt', "ignored\n");

        $conductor = new class extends Conductor {
            public array $lines = [];

            public function __construct()
            {
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'streams' => $root,
            ],
        ]);

        $conductor->listStreams();
        $output = implode(PHP_EOL, $conductor->lines);

        $this->assertMatchesRegularExpression('/\[\/\]\s+alpha/', $output);
        $this->assertMatchesRegularExpression('/\[x\]\s+bravo/', $output);
        $this->assertStringNotContainsString('example', $output);
        $this->assertStringNotContainsString('notes', $output);
        $this->assertLessThan(strpos($output, 'bravo'), strpos($output, 'alpha'));

        @unlink($root . '/alpha.conf');
        @unlink($root . '/bravo.disabled');
        @unlink($root . '/example.conf.example');
        @unlink($root . '/notes.txt');
        @rmdir($root);
    }

    public function testGeoIpDatabaseDefaultsUseConfiguredDirectoryAndDbIpMonthlyDownloads(): void
    {
        $conductor = $this->makeConductorWithConfig((object) [
            'paths' => (object) [
                'geoip' => '/custom/geoip/',
            ],
        ]);

        $reflection = new ReflectionClass(Conductor::class);

        $directory = $reflection->getMethod('geoIpDatabaseDirectory');
        $this->assertSame('/custom/geoip', $directory->invoke($conductor));

        $urls = $reflection->getMethod('geoIpDatabaseDownloadUrls');
        $downloads = $urls->invoke($conductor);

        $this->assertCount(2, $downloads);
        $this->assertMatchesRegularExpression(
            '#^https://download\.db-ip\.com/free/dbip-country-lite-\d{4}-\d{2}\.mmdb\.gz$#',
            $downloads[0]
        );
    }

    public function testGeoIpDatabaseCommandUsesUpdateFlag(): void
    {
        $entrypoint = file_get_contents(__DIR__ . '/../bin/conductor.php');
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('$conductor->geoIpDbControl();', $entrypoint);
        $this->assertStringContainsString('geoipdb --update', $entrypoint);
        $this->assertStringContainsString('geoipdb\' => [\'--update\', \'--url=\']', $source);
        $this->assertStringContainsString('$this->isFlagSet(\'update\')', $source);
        $this->assertStringContainsString('$this->setDownloadedFileOwner($target)', $source);
        $this->assertStringContainsString('$this->setDownloadedFileOwner($source_file)', $source);
        $this->assertStringContainsString('/usr/bin/chown www-data ', $source);
        $this->assertStringContainsString('sudo conductor geoipdb --update', $readme);
        $this->assertStringNotContainsString('geoipdb update', $entrypoint . $source . $readme);
    }

    public function testStatsDataCountsVirtualHostAndStreamConfigurationFiles(): void
    {
        $root = sys_get_temp_dir() . '/conductor-stats-configs-' . uniqid();
        $appconfs = $root . '/appconfs';
        $streams = $root . '/streams';
        mkdir($root);
        mkdir($appconfs);
        mkdir($streams);

        file_put_contents($appconfs . '/alpha.conf', '');
        file_put_contents($appconfs . '/bravo.conf', '');
        file_put_contents($appconfs . '/charlie.disabled', '');
        file_put_contents($appconfs . '/ignored.txt', '');
        file_put_contents($streams . '/tls.conf', '');
        file_put_contents($streams . '/ssh.disabled', '');
        file_put_contents($streams . '/nextcloud.conf.example', '');
        file_put_contents($streams . '/notes.txt', '');

        $conductor = $this->makeConductorWithConfig((object) [
            'paths' => (object) [
                'appconfs' => $appconfs,
                'streams' => $streams,
            ],
        ]);

        $method = (new ReflectionClass(Conductor::class))->getMethod('nginxConfigurationStats');
        $stats = $method->invoke($conductor);

        $this->assertSame([
            'enabled' => 2,
            'disabled' => 1,
        ], $stats['virtual_hosts']);
        $this->assertSame([
            'enabled' => 1,
            'disabled' => 2,
        ], $stats['streams']);

        foreach ([
            $appconfs . '/alpha.conf',
            $appconfs . '/bravo.conf',
            $appconfs . '/charlie.disabled',
            $appconfs . '/ignored.txt',
            $streams . '/tls.conf',
            $streams . '/ssh.disabled',
            $streams . '/nextcloud.conf.example',
            $streams . '/notes.txt',
        ] as $file) {
            @unlink($file);
        }
        @rmdir($streams);
        @rmdir($appconfs);
        @rmdir($root);
    }

    public function testApplicationStatsCountsVisibleApplicationDirectories(): void
    {
        $root = sys_get_temp_dir() . '/conductor-app-stats-' . uniqid();
        mkdir($root);
        mkdir($root . '/alpha');
        mkdir($root . '/bravo');
        mkdir($root . '/.hidden');
        file_put_contents($root . '/not-a-directory', '');

        $conductor = $this->makeConductorWithConfig((object) [
            'paths' => (object) [
                'apps' => $root,
            ],
        ]);

        $method = (new ReflectionClass(Conductor::class))->getMethod('applicationStats');

        $this->assertSame(['total' => 2], $method->invoke($conductor));

        @unlink($root . '/not-a-directory');
        @rmdir($root . '/.hidden');
        @rmdir($root . '/bravo');
        @rmdir($root . '/alpha');
        @rmdir($root);
    }

    public function testPrometheusMetricsExposeNumericStatsAndSkipNAValues(): void
    {
        $stats = [
            'system' => [
                'operating_system_uptime_seconds' => 123,
                'nginx_daemon_uptime_seconds' => 45,
            ],
            'memory' => [
                'utilisation_percent' => 50,
                'used_mb' => 128,
                'available_mb' => 384,
                'total_mb' => 512,
            ],
            'nginx_configuration' => [
                'virtual_hosts' => [
                    'enabled' => 2,
                    'disabled' => 1,
                ],
                'streams' => [
                    'enabled' => 1,
                    'disabled' => 3,
                ],
            ],
            'applications' => [
                'total' => 4,
            ],
            'configured_ip_addresses' => [
                'eth0 192.0.2.10/24',
                'N/A',
            ],
            'nginx_status' => [
                'active_connections' => 5,
                'accepted_connections' => 100,
                'handled_connections' => 99,
                'requests' => 250,
                'reading' => 1,
                'writing' => 2,
                'waiting' => 3,
            ],
        ];

        $method = (new ReflectionClass(Conductor::class))->getMethod('prometheusMetrics');
        $metrics = $method->invoke($this->makeConductor(), $stats);

        $this->assertStringContainsString("# TYPE conductor_up gauge\nconductor_up 1", $metrics);
        $this->assertStringContainsString("conductor_system_uptime_seconds 123\n", $metrics);
        $this->assertStringContainsString("conductor_memory_used_bytes 134217728\n", $metrics);
        $this->assertStringContainsString("conductor_nginx_virtual_hosts_enabled 2\n", $metrics);
        $this->assertStringContainsString("conductor_applications_total 4\n", $metrics);
        $this->assertStringContainsString("conductor_configured_ip_addresses_total 1\n", $metrics);
        $this->assertStringContainsString("conductor_nginx_status_available 1\n", $metrics);
        $this->assertStringContainsString("conductor_nginx_requests_total 250\n", $metrics);
        $this->assertStringNotContainsString('N/A', $metrics);
    }

    public function testStatsParsesMemoryUtilisationFromMeminfo(): void
    {
        $conductor = $this->makeConductor();
        $method = (new ReflectionClass(Conductor::class))->getMethod('parseMemoryStats');

        $stats = $method->invoke($conductor, implode("\n", [
            'MemTotal:        2048000 kB',
            'MemFree:          128000 kB',
            'MemAvailable:     512000 kB',
            'Buffers:           64000 kB',
            'Cached:           256000 kB',
        ]));

        $this->assertSame(75, $stats['utilisation_percent']);
        $this->assertSame(1500, $stats['used_mb']);
        $this->assertSame(500, $stats['available_mb']);
        $this->assertSame(2000, $stats['total_mb']);
    }

    public function testStatsTextOutputIncludesMemorySection(): void
    {
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $this->assertStringContainsString("\$this->writeln('Memory');", $source);
        $this->assertStringContainsString("'   Utilisation: ' . \$stats['memory']['utilisation_percent'] . '%'", $source);
        $this->assertStringContainsString("'   Used:         ' . \$stats['memory']['used_mb'] . 'MB'", $source);
        $this->assertStringContainsString("'   Available:   ' . \$stats['memory']['available_mb'] . 'MB'", $source);
    }

    public function testMetricsCommandIsExposedInCliHelpAndCompletion(): void
    {
        $entrypoint = file_get_contents(__DIR__ . '/../bin/conductor.php');
        $source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $this->assertStringContainsString('case "metrics":', $entrypoint);
        $this->assertStringContainsString('$conductor->metrics();', $entrypoint);
        $this->assertStringContainsString('metrics             Display Prometheus textfile metrics', $entrypoint);
        $this->assertStringContainsString("'metrics',", $source);
    }

    public function testValidateProxyTargetAcceptsHttpHostsWithPorts(): void
    {
        $conductor = $this->makeConductor();
        $method = (new ReflectionClass(Conductor::class))->getMethod('validateProxyTarget');

        $this->assertSame('http://localhost:9000', $method->invoke($conductor, Conductor::DEFAULT_PROXY_TARGET));
        $this->assertSame('http://127.24.54.54:8000', $method->invoke($conductor, 'http://127.24.54.54:8000'));
        $this->assertSame('https://backend.example.com:8443', $method->invoke($conductor, 'https://backend.example.com:8443/'));
        $this->assertSame('http://localhost:9000', $method->invoke($conductor, 'http://localhost:9000'));
    }

    public function testProxyTargetPlaceholdersUseAppNameUpstreamAndTargetHost(): void
    {
        $conductor = $this->makeConductor();
        $reflection = new ReflectionClass(Conductor::class);

        $property = $reflection->getProperty('appname');
        $property->setValue($conductor, 'my-proxy');

        $method = $reflection->getMethod('proxyTargetPlaceholders');
        $this->assertSame([
            '@@TARGET_SCHEME@@' => 'http',
            '@@TARGET_HOST@@' => '127.24.54.54:8000',
            '@@UPSTREAM@@' => 'my_proxy',
        ], $method->invoke($conductor, 'http://127.24.54.54:8000'));
    }

    public function testAuthUsersCanBeSetAndDeletedFromConfiguredAuthDirectory(): void
    {
        $auth = sys_get_temp_dir() . '/conductor-auth-' . uniqid();
        mkdir($auth);

        $conductor = new class extends Conductor {
            public function __construct()
            {
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'pwdbs' => $auth,
            ],
        ]);
        $reflection->getProperty('appname')->setValue($conductor, 'myapp');

        $set = $reflection->getMethod('setAuthUser');
        $set->invoke($conductor, 'alice', 'secret');

        $auth_file = $auth . '/.htpasswd_myapp';
        $this->assertFileExists($auth_file);
        $this->assertStringStartsWith('alice:', trim(file_get_contents($auth_file)));

        $users = $reflection->getMethod('authUsers');
        $loaded_users = $users->invoke($conductor);
        $this->assertArrayHasKey('alice', $loaded_users);
        $this->assertTrue(password_verify('secret', $loaded_users['alice']));

        $delete = $reflection->getMethod('deleteAuthUser');
        $delete->invoke($conductor, 'alice');

        $this->assertSame('', file_get_contents($auth_file));

        @unlink($auth_file);
        @rmdir($auth);
    }

    public function testAuthConfigCanBeEnabledAndDisabledBetweenMarkers(): void
    {
        $root = sys_get_temp_dir() . '/conductor-auth-config-' . uniqid();
        $configs = $root . '/configs';
        $auth = $root . '/auth';
        mkdir($root);
        mkdir($configs);
        mkdir($auth);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            'server {',
            '    ' . Conductor::AUTH_START_MARKER,
            '    #auth_basic           "Restricted";',
            '    #auth_basic_user_file /etc/conductor/pwdbs/.htpasswd_myapp;',
            '    ' . Conductor::AUTH_END_MARKER,
            '}',
        ]));

        $conductor = new class extends Conductor {
            public array $calls = [];

            public function __construct()
            {
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function input($question, $default = '', $options = [])
            {
                return self::OPTION_NO;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'appconfs' => $configs,
                'pwdbs' => $auth,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);
        $reflection->getProperty('appname')->setValue($conductor, 'myapp');

        $method = $reflection->getMethod('updateApplicationAuthConfig');
        $method->invoke($conductor, true);

        $enabled = file_get_contents($config);
        $this->assertStringContainsString('    auth_basic           "Restricted";', $enabled);
        $this->assertFileExists($auth . '/.htpasswd_myapp');

        $method->invoke($conductor, false);
        $disabled = file_get_contents($config);
        $this->assertStringContainsString('    #auth_basic           "Restricted";', $disabled);

        @unlink($auth . '/.htpasswd_myapp');
        @unlink($config);
        @rmdir($auth);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testProtectConfigCanBeEnabledAndDisabled(): void
    {
        $root = sys_get_temp_dir() . '/conductor-protect-config-' . uniqid();
        $configs = $root . '/configs';
        mkdir($root);
        mkdir($configs);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            'server {',
            '    access_log /var/log/nginx/myapp.access.log;',
            '    ' . Conductor::PROTECTION_START_MARKER,
            '    #access_log     /tmp/conductor_myapp.seclog conductor_security;',
            '    ' . Conductor::PROTECTION_END_MARKER,
            '}',
        ]));

        $conductor = new class extends Conductor {
            public array $commands = [];

            public function __construct()
            {
            }

            public function callWithOutput($command, &$output)
            {
                $output = [];
                return 0;
            }

            public function input($question, $default = '', $options = [])
            {
                return self::OPTION_NO;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'appconfs' => $configs,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);
        $reflection->getProperty('appname')->setValue($conductor, 'myapp');

        $method = $reflection->getMethod('updateApplicationProtectionConfig');
        $method->invoke($conductor, true);

        $enabled = file_get_contents($config);
        $this->assertStringContainsString('    access_log     /tmp/conductor_myapp.seclog conductor_security;', $enabled);
        $this->assertStringContainsString('    access_log /var/log/nginx/myapp.access.log;', $enabled);

        $method->invoke($conductor, false);
        $disabled = file_get_contents($config);
        $this->assertStringContainsString('    #access_log     /tmp/conductor_myapp.seclog conductor_security;', $disabled);
        $this->assertStringContainsString('    access_log /var/log/nginx/myapp.access.log;', $disabled);

        @unlink($config);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testProtectConfigAutoReloadsWhenRequested(): void
    {
        $root = sys_get_temp_dir() . '/conductor-protect-reload-' . uniqid();
        $configs = $root . '/configs';
        mkdir($root);
        mkdir($configs);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            'server {',
            '    ' . Conductor::PROTECTION_START_MARKER,
            '    #access_log /tmp/conductor_myapp.seclog conductor_security;',
            '    ' . Conductor::PROTECTION_END_MARKER,
            '}',
        ]));

        $conductor = new class extends Conductor {
            public array $calls = [];

            public function __construct()
            {
            }

            public function isFlagSet($flag)
            {
                return $flag == 'auto-reload';
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function callWithOutput($command, &$output)
            {
                $output = [];
                return 0;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'appconfs' => $configs,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);
        $reflection->getProperty('appname')->setValue($conductor, 'myapp');

        $method = $reflection->getMethod('updateApplicationProtectionConfig');
        $method->invoke($conductor, true);

        $this->assertContains('service nginx reload', $conductor->calls);

        @unlink($config);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testProtectConfigStartsFail2BanWhenNotRunning(): void
    {
        $root = sys_get_temp_dir() . '/conductor-protect-fail2ban-' . uniqid();
        $configs = $root . '/configs';
        mkdir($root);
        mkdir($configs);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            'server {',
            '    ' . Conductor::PROTECTION_START_MARKER,
            '    #access_log /tmp/conductor_myapp.seclog conductor_security;',
            '    ' . Conductor::PROTECTION_END_MARKER,
            '}',
        ]));

        $conductor = new class extends Conductor {
            public array $commands = [];
            private int $ping_count = 0;

            public function __construct()
            {
            }

            public function callWithOutput($command, &$output)
            {
                $this->commands[] = $command;
                $output = [];

                if ($command == '/usr/sbin/nginx -t 2>&1') {
                    return 0;
                }

                if ($command == 'command -v fail2ban-client 2>/dev/null') {
                    $output = ['/usr/bin/fail2ban-client'];
                    return 0;
                }

                if ($command == 'fail2ban-client ping 2>&1') {
                    $this->ping_count++;
                    return $this->ping_count === 1 ? 1 : 0;
                }

                if ($command == 'command -v systemctl 2>/dev/null') {
                    $output = ['/usr/bin/systemctl'];
                    return 0;
                }

                if ($command == 'systemctl start fail2ban 2>&1') {
                    return 0;
                }

                return 0;
            }

            public function input($question, $default = '', $options = [])
            {
                return self::OPTION_NO;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'appconfs' => $configs,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);
        $reflection->getProperty('appname')->setValue($conductor, 'myapp');

        $method = $reflection->getMethod('updateApplicationProtectionConfig');
        $method->invoke($conductor, true);

        $this->assertContains('fail2ban-client ping 2>&1', $conductor->commands);
        $this->assertContains('systemctl start fail2ban 2>&1', $conductor->commands);

        @unlink($config);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testWafConfigCanBeEnabledAndDisabled(): void
    {
        $root = sys_get_temp_dir() . '/conductor-waf-config-' . uniqid();
        $configs = $root . '/configs';
        $waf = $root . '/waf';
        $error_pages = $root . '/error-pages';
        mkdir($root);
        mkdir($configs);
        mkdir($waf);
        mkdir($error_pages);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            'server {',
            '    ' . Conductor::PROTECTION_START_MARKER,
            '    #access_log     /tmp/conductor_myapp.seclog conductor_security;',
            '    ' . Conductor::PROTECTION_END_MARKER,
            '    ' . Conductor::WAF_START_MARKER,
            '    include /etc/conductor/wafs/myapp.conf;',
            '    ' . Conductor::WAF_END_MARKER,
            '}',
        ]));
        file_put_contents($waf . '/myapp.conf', '# waf');

        $conductor = new class extends Conductor {
            public array $calls = [];

            public function __construct()
            {
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function input($question, $default = '', $options = [])
            {
                return self::OPTION_NO;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'appconfs' => $configs,
                'wafs' => $waf,
                'errorpages' => $error_pages,
                'templates' => __DIR__ . '/../configs/common',
            ],
            'permissions' => (object) [
                'webuser' => get_current_user(),
                'webgroup' => get_current_user(),
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);
        $reflection->getProperty('appname')->setValue($conductor, 'myapp');

        $method = $reflection->getMethod('updateApplicationWafConfig');
        $method->invoke($conductor, false);
        $this->assertStringContainsString('    #include /etc/conductor/wafs/myapp.conf;', file_get_contents($config));

        $method->invoke($conductor, true);
        $enabled = file_get_contents($config);
        $this->assertStringContainsString('    include /etc/conductor/wafs/myapp.conf;', $enabled);
        $this->assertStringContainsString('    access_log     /tmp/conductor_myapp.seclog conductor_security;', $enabled);

        $method->invoke($conductor, false);
        $disabled = file_get_contents($config);
        $this->assertStringContainsString('    #include /etc/conductor/wafs/myapp.conf;', $disabled);
        $this->assertStringContainsString('    #access_log     /tmp/conductor_myapp.seclog conductor_security;', $disabled);
        $this->assertSame(3, substr_count(implode(PHP_EOL, $conductor->calls), 'systemctl restart fail2ban 2>&1'));

        @unlink($waf . '/myapp.conf');
        @unlink($error_pages . '/406.html');
        @unlink($config);
        @rmdir($error_pages);
        @rmdir($waf);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testWafRulesetsUpdateCommunityDownloadsRulesetsAndReloadsNginx(): void
    {
        $root = sys_get_temp_dir() . '/conductor-waf-rulesets-' . uniqid();
        mkdir($root);

        $conductor = new class(['conductor', 'waf', 'rulesets', '--update-community']) extends Conductor {
            public array $lines = [];
            public array $urls = [];
            public array $commands = [];

            public function __construct($argv)
            {
                \CliApplication::__construct($argv);
            }

            protected function downloadUrl($url)
            {
                $this->urls[] = $url;

                if (str_contains($url, 'xcaler_ai_bots.list')) {
                    return null;
                }

                return "# downloaded from " . $url . "\n"
                    . "# Auto-updated from Xcaler Community lists (https://lists.xcaler.com) at {DATETIME}\n";
            }

            public function callWithOutput($command, &$output)
            {
                $this->commands[] = $command;
                $output = [];
                return 0;
            }

            public function call($command)
            {
                $this->commands[] = $command;
                return '';
            }

            public function callWithExitCode($command)
            {
                $this->commands[] = $command;
                return 0;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'templates' => $root,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->wafControl();

        foreach ([
            'search_engines',
            'sql_injection',
            'path_traversal',
            'common_paths',
        ] as $type) {
            $path = $root . '/xcaler_community_' . $type . '.conf';
            $content = file_get_contents($path);
            $this->assertFileExists($path);
            $this->assertStringContainsString('https://lists.xcaler.com/xcaler_' . $type . '.list', $content);
            $this->assertStringNotContainsString('{DATETIME}', $content);
            $this->assertMatchesRegularExpression('/Auto-updated from Xcaler Community lists \(https:\/\/lists\.xcaler\.com\) at \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z]+/', $content);
            @unlink($path);
        }

        $this->assertFileDoesNotExist($root . '/xcaler_community_ai_bots.conf');
        $this->assertContains('https://lists.xcaler.com/xcaler_ai_bots.list', $conductor->urls);
        $this->assertContains('xcaler_community_search_engines.conf: updated!', $conductor->lines);
        $this->assertContains('xcaler_community_ai_bots.conf: failed!', $conductor->lines);
        $this->assertContains('/usr/sbin/nginx -t 2>&1', $conductor->commands);
        $this->assertContains('systemctl restart fail2ban 2>&1', $conductor->commands);
        $this->assertContains('service nginx reload', $conductor->commands);
        foreach (['search_engines', 'sql_injection', 'path_traversal', 'common_paths'] as $type) {
            $this->assertContains('/usr/bin/chown www-data ' . escapeshellarg($root . '/xcaler_community_' . $type . '.conf'), $conductor->commands);
        }

        @rmdir($root);
    }

    public function testWafRulesetsUpdateCommunityRevertsWhenNginxTestFails(): void
    {
        $root = sys_get_temp_dir() . '/conductor-waf-rulesets-fail-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/xcaler_community_search_engines.conf', "# previous\n");

        $conductor = new class(['conductor', 'waf', 'rulesets', '--update-community']) extends Conductor {
            public array $lines = [];
            public array $commands = [];

            public function __construct($argv)
            {
                \CliApplication::__construct($argv);
            }

            protected function downloadUrl($url)
            {
                return '# invalid downloaded ruleset from ' . $url . "\n";
            }

            public function callWithOutput($command, &$output)
            {
                $this->commands[] = $command;
                $output = ['nginx: configuration file test failed'];
                return 1;
            }

            public function call($command)
            {
                $this->commands[] = $command;
                return '';
            }

            public function callWithExitCode($command)
            {
                $this->commands[] = $command;
                return 0;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'templates' => $root,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->wafControl();

        $this->assertSame("# previous\n", file_get_contents($root . '/xcaler_community_search_engines.conf'));
        foreach (['ai_bots', 'sql_injection', 'path_traversal', 'common_paths'] as $type) {
            $this->assertFileDoesNotExist($root . '/xcaler_community_' . $type . '.conf');
        }
        $this->assertContains('Tests failed, reverting rulesets back to previous ruleset configuration!', $conductor->lines);
        $this->assertNotContains('service nginx reload', $conductor->commands);
        $this->assertNotContains('systemctl restart fail2ban 2>&1', $conductor->commands);

        @unlink($root . '/xcaler_community_search_engines.conf');
        @rmdir($root);
    }

    public function testConductorTemplateEnablesNginxAutoReloadByDefault(): void
    {
        $config = json_decode(file_get_contents(__DIR__ . '/../bin/conf/conductor.debian.template.json'));

        $this->assertTrue($config->{'auto-reload-nginx'});
    }

    public function testGlobalNginxAutoReloadSkipsPromptAndReloadsAfterSuccessfulConfigTest(): void
    {
        $conductor = new class extends Conductor {
            public array $calls = [];
            public array $questions = [];

            public function __construct()
            {
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function callWithOutput($command, &$output)
            {
                $output = [];
                return 0;
            }

            public function input($question, $default = '', $options = [])
            {
                $this->questions[] = $question;
                return self::OPTION_NO;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => true,
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $method = $reflection->getMethod('promptGracefulNginxReload');
        $method->invoke($conductor, 'test change');

        $this->assertSame([], $conductor->questions);
        $this->assertContains('service nginx reload', $conductor->calls);
    }

    public function testLetsEncryptCertificateSuccessCanEnableSslConfiguration(): void
    {
        $root = sys_get_temp_dir() . '/conductor-letsencrypt-enable-' . uniqid();
        $configs = $root . '/configs';
        mkdir($root);
        mkdir($configs);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            '# :: Managed domains: [example.com www.example.com]',
            'server {',
            '    # -- C:Start Default (HTTP) Main Block -- #',
            '    listen 80;',
            '    # -- C:End Default (HTTP) Main Block -- #',
            '    # -- C:Start Auto-LetsEncrypt Main Block -- #',
            '    #listen 443 ssl;',
            '    #ssl_certificate /etc/letsencrypt/live/myapp/fullchain.pem;',
            '    # -- C:End Auto-LetsEncrypt Main Block -- #',
            '}',
        ]));

        $conductor = new class extends Conductor {
            public array $questions = [];
            public array $commands = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function callWithExitCode($command)
            {
                $this->commands[] = $command;
                return 0;
            }

            public function callWithOutput($command, &$output)
            {
                $output = [];
                return 0;
            }

            public function input($question, $default = '', $options = [])
            {
                $this->questions[] = $question;
                return strpos($question, 'Enable SSL configuration now?') !== false
                    ? self::OPTION_YES
                    : self::OPTION_NO;
            }

            public function writeln($line = '')
            {
            }

            public function endWithSuccess()
            {
                throw new \RuntimeException('success');
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'appconfs' => $configs,
                'apps' => $root,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
            'admin' => (object) [
                'email' => 'admin@example.com',
            ],
            'cmdtpls' => (object) [
                'letsencryptgen' => 'certbot --cert-name=__APP__ --deploy-hook=__NGINX_RELOAD_CMD__ --email=__EMAIL__ -d __DOMAINS__',
            ],
        ]);

        try {
            $conductor->generateLetsEncryptCertificate();
        } catch (\RuntimeException $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $updated = file_get_contents($config);
        $this->assertContains('LetsEncrypt certificate request successful. Enable SSL configuration now?', $conductor->questions);
        $this->assertStringContainsString('#listen 80;', $updated);
        $this->assertStringContainsString('    listen 443 ssl;', $updated);
        $this->assertStringContainsString('example.com,www.example.com', $conductor->commands[0]);
        $this->assertStringContainsString('/etc/conductor/utils/letsencrypt_webhook.sh deploy myapp', $conductor->commands[0]);

        @unlink($config);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testLetsEncryptWebhookConfigureUpdatesWebhookConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-letsencrypt-webhook-' . uniqid();
        mkdir($root);

        $config_path = $root . '/letsencrypt-webhook.conf';
        $endpoint = 'https://n8n.example.com/webhook/8b4e7040-3746-4120-b317-50110f074a53';
        $conductor = new class($endpoint) extends Conductor {
            public array $lines = [];
            private string $endpoint;

            public function __construct($endpoint)
            {
                $this->endpoint = $endpoint;
            }

            public function getCommand($part, $default = false)
            {
                return [1 => 'letsencrypt', 2 => 'webhook'][$part] ?? $default;
            }

            public function getOption($name, $default = false)
            {
                return $name == 'configure' ? $this->endpoint : $default;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'letsencrypt_webhook' => $config_path,
            ],
        ]);

        $conductor->generateLetsEncryptCertificate();

        $this->assertSame('url = ' . $endpoint . PHP_EOL, file_get_contents($config_path));
        $this->assertContains('Updated LetsEncrypt webhook endpoint: ' . $endpoint, $conductor->lines);

        @unlink($config_path);
        @rmdir($root);
    }

    public function testApplicationWafConfigIsCreatedFromTemplate(): void
    {
        $root = sys_get_temp_dir() . '/conductor-waf-template-' . uniqid();
        $templates = $root . '/templates';
        $waf = $root . '/waf';
        $error_pages = $root . '/error-pages';
        mkdir($root);
        mkdir($templates);
        mkdir($waf);
        mkdir($error_pages);

        file_put_contents($templates . '/waf_html.tpl', "location = /test-@@APPNAME@@ { return 204; }\n");
        file_put_contents($templates . '/406.html.tpl', "Request rejected.\n");

        $conductor = $this->makeConductorWithConfig((object) [
            'paths' => (object) [
                'templates' => $root,
                'wafs' => $waf,
                'errorpages' => $error_pages,
            ],
            'permissions' => (object) [
                'webuser' => get_current_user(),
                'webgroup' => get_current_user(),
            ],
        ]);
        (new ReflectionClass(Conductor::class))->getProperty('appname')->setValue($conductor, 'myapp');

        $method = (new ReflectionClass(Conductor::class))->getMethod('createApplicationWafConfig');
        $method->invoke($conductor, 'html', ['@@APPNAME@@' => 'myapp']);

        $this->assertSame("location = /test-myapp { return 204; }\n", file_get_contents($waf . '/myapp.conf'));

        @unlink($waf . '/myapp.conf');
        @unlink($error_pages . '/406.html');
        @unlink($templates . '/406.html.tpl');
        @unlink($templates . '/waf_html.tpl');
        @rmdir($error_pages);
        @rmdir($waf);
        @rmdir($templates);
        @rmdir($root);
    }

    public function testDumpApplicationConfigWritesRawVirtualhostOrWafConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-dump-config-' . uniqid();
        $configs = $root . '/configs';
        $waf = $root . '/waf';
        $error_pages = $root . '/error-pages';
        mkdir($root);
        mkdir($configs);
        mkdir($waf);
        mkdir($error_pages);

        file_put_contents($configs . '/myapp.conf', "server { return 204; }\n");
        file_put_contents($waf . '/myapp.conf', "# waf\n");

        $conductor = new class extends Conductor {
            private bool $waf = false;

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function isFlagSet($flag)
            {
                return $flag == 'waf' && $this->waf;
            }

            public function useWaf()
            {
                $this->waf = true;
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $root,
                'appconfs' => $configs,
                'wafs' => $waf,
                'errorpages' => $error_pages,
                'templates' => __DIR__ . '/../configs/common',
            ],
            'permissions' => (object) [
                'webuser' => get_current_user(),
                'webgroup' => get_current_user(),
            ],
        ]);

        ob_start();
        $conductor->dumpApplicationConfig();
        $this->assertSame("server { return 204; }\n", ob_get_clean());

        $conductor->useWaf();

        ob_start();
        $conductor->dumpApplicationConfig();
        $this->assertSame("# waf\n", ob_get_clean());

        @unlink($configs . '/myapp.conf');
        @unlink($waf . '/myapp.conf');
        @unlink($error_pages . '/406.html');
        @rmdir($error_pages);
        @rmdir($waf);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testLoadApplicationConfigReadsStdinValidatesAndWritesVirtualhostConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-load-config-' . uniqid();
        $configs = $root . '/configs';
        mkdir($root);
        mkdir($configs);

        $config_path = $configs . '/myapp.conf';
        file_put_contents($config_path, "server { return 204; }\n");

        $conductor = new class extends Conductor {
            public array $calls = [];
            public array $questions = [];
            private string $stdin = "server { return 418; }\n";

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function input($question, $default = '', $options = [])
            {
                $this->questions[] = $question;
                return self::OPTION_NO;
            }

            protected function readStdin()
            {
                return $this->stdin;
            }

            public function writeln($line = '')
            {
            }
        };

        $reflection = new ReflectionClass(Conductor::class);
        $reflection->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'apps' => $root,
                'appconfs' => $configs,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->loadApplicationConfig();

        $this->assertSame("server { return 418; }\n", file_get_contents($config_path));
        $this->assertSame(['/usr/sbin/nginx -t 2>&1', 'service nginx reload'], $conductor->calls);
        $this->assertSame([], $conductor->questions);

        @unlink($config_path);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testLoadWafConfigRestartsFail2BanAfterValidation(): void
    {
        $root = sys_get_temp_dir() . '/conductor-load-waf-config-' . uniqid();
        $configs = $root . '/configs';
        $waf = $root . '/waf';
        $error_pages = $root . '/error-pages';
        $templates = $root . '/templates';
        mkdir($root);
        mkdir($configs);
        mkdir($waf);
        mkdir($error_pages);
        mkdir($templates);
        mkdir($templates . '/templates');

        file_put_contents($configs . '/myapp.conf', "server { return 204; }\n");
        file_put_contents($waf . '/myapp.conf', "# old waf\n");
        file_put_contents($templates . '/templates/406.html.tpl', "Request rejected.\n");

        $conductor = new class extends Conductor {
            public array $calls = [];
            private string $stdin = "# new waf\n";

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'myapp' : $default;
            }

            public function isFlagSet($flag)
            {
                return $flag == 'waf';
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            protected function readStdin()
            {
                return $this->stdin;
            }

            public function writeln($line = '')
            {
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'apps' => $root,
                'appconfs' => $configs,
                'wafs' => $waf,
                'errorpages' => $error_pages,
                'templates' => $templates,
            ],
            'permissions' => (object) [
                'webuser' => get_current_user(),
                'webgroup' => get_current_user(),
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->loadApplicationConfig();

        $this->assertSame("# new waf\n", file_get_contents($waf . '/myapp.conf'));
        $this->assertSame([
            '/usr/sbin/nginx -t 2>&1',
            'systemctl restart fail2ban 2>&1',
            'service nginx reload',
        ], $conductor->calls);

        @unlink($configs . '/myapp.conf');
        @unlink($waf . '/myapp.conf');
        @unlink($error_pages . '/406.html');
        @unlink($templates . '/templates/406.html.tpl');
        @rmdir($templates . '/templates');
        @rmdir($templates);
        @rmdir($error_pages);
        @rmdir($waf);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testDumpAndLoadStreamConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-stream-config-' . uniqid();
        $streams = $root . '/streams';
        mkdir($root);
        mkdir($streams);
        file_put_contents($streams . '/mysql.conf', "stream { # old\n}\n");

        $conductor = new class extends Conductor {
            public array $calls = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'mysql' : $default;
            }

            public function isFlagSet($flag)
            {
                return $flag == 'stream';
            }

            protected function readStdin()
            {
                return "stream { # new\n}\n";
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function writeln($line = '')
            {
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'apps' => $root,
                'streams' => $streams,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->loadApplicationConfig();
        $this->assertSame("stream { # new\n}\n", file_get_contents($streams . '/mysql.conf'));
        $this->assertSame(['/usr/sbin/nginx -t 2>&1', 'service nginx reload'], $conductor->calls);

        ob_start();
        $conductor->dumpApplicationConfig();
        $this->assertSame("stream { # new\n}\n", ob_get_clean());

        @unlink($streams . '/mysql.conf');
        @rmdir($streams);
        @rmdir($root);
    }

    public function testDisableAndEnableStreamConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-toggle-stream-' . uniqid();
        $streams = $root . '/streams';
        mkdir($root);
        mkdir($streams);
        file_put_contents($streams . '/mysql.conf', "stream {}\n");

        $conductor = new class extends Conductor {
            public array $calls = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'mysql' : $default;
            }

            public function isFlagSet($flag)
            {
                return $flag == 'stream' || $flag == 'auto-reload';
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function writeln($line = '')
            {
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'apps' => $root,
                'streams' => $streams,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->disableApplication();
        $this->assertFileDoesNotExist($streams . '/mysql.conf');
        $this->assertFileExists($streams . '/mysql.disabled');

        $conductor->enableApplication();
        $this->assertFileExists($streams . '/mysql.conf');
        $this->assertFileDoesNotExist($streams . '/mysql.disabled');
        $this->assertSame([
            '/usr/sbin/nginx -t 2>&1',
            'service nginx reload',
            '/usr/sbin/nginx -t 2>&1',
            'service nginx reload',
        ], $conductor->calls);

        @unlink($streams . '/mysql.conf');
        @rmdir($streams);
        @rmdir($root);
    }

    public function testCreateAndDestroyStreamConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-create-stream-' . uniqid();
        $streams = $root . '/streams';
        mkdir($root);

        $conductor = new class extends Conductor {
            public array $calls = [];

            public function __construct()
            {
            }

            public function getCommand($part, $default = false)
            {
                return $part == 2 ? 'mail-relay' : $default;
            }

            public function isFlagSet($flag)
            {
                return $flag == 'stream';
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function call($command)
            {
                $this->calls[] = $command;
                return '';
            }

            public function writeln($line = '')
            {
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'auto-reload-nginx' => false,
            'paths' => (object) [
                'apps' => $root,
                'streams' => $streams,
            ],
            'binaries' => (object) [
                'nginx' => '/usr/sbin/nginx',
            ],
            'services' => (object) [
                'nginx' => (object) [
                    'reload' => 'service nginx reload',
                ],
            ],
        ]);

        $conductor->newApplication();
        $config = $streams . '/mail-relay.conf';
        $this->assertSame(
            "# Add your TCP/UDP stream configuration here!\n#stream {\n#\n#}\n",
            file_get_contents($config)
        );

        rename($config, $streams . '/mail-relay.disabled');
        $conductor->destroy();

        $this->assertFileDoesNotExist($streams . '/mail-relay.disabled');
        file_put_contents($config, "# enabled stream\n");
        $conductor->destroy();
        $this->assertFileDoesNotExist($config);
        $this->assertSame([
            '/usr/sbin/nginx -t 2>&1',
            'service nginx reload',
            '/usr/sbin/nginx -t 2>&1',
            'service nginx reload',
            '/usr/sbin/nginx -t 2>&1',
            'service nginx reload',
        ], $conductor->calls);

        @rmdir($streams);
        @rmdir($root);
    }

    public function testDefaultWafTemplatesIncludeSharedProtectionBlocks(): void
    {
        foreach (['html', 'laravel', 'proxy', 'wordpress'] as $template) {
            $waf_content = file_get_contents(__DIR__ . '/../configs/common/templates/waf_' . $template . '.tpl');
            $vhost_content = file_get_contents(__DIR__ . '/../configs/common/templates/vhost_' . $template . '.tpl');

            $this->assertStringContainsString(
                '# Conductor managed (Xcaler) WAF configured for @@APPNAME@@',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/xcaler_community_search_engines.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/xcaler_community_ai_bots.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/xcaler_community_sql_injection.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/xcaler_community_path_traversal.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/xcaler_community_common_paths.conf;',
                $waf_content
            );
            $this->assertStringContainsString('if ($conductor_geoip_country_code !~ ^GB$)', $waf_content);
            $this->assertStringContainsString('if ($conductor_geoip_country_code !~ ^(GB|US|DE)$)', $waf_content);
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/conductor_waf_error_pages.conf;',
                $waf_content
            );
            $this->assertStringNotContainsString('error_page 406 /.406.html;', $waf_content);
            $this->assertStringNotContainsString('error_page 406 /.406.html;', $vhost_content);
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/conductor_error_pages.conf;',
                $vhost_content
            );
            $this->assertStringContainsString(
                'set             $conductor_application "@@APPNAME@@";',
                $vhost_content
            );
            $this->assertStringContainsString('X-Frame-Options', $vhost_content);
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/conductor_quiet_common_requests.conf;',
                $vhost_content
            );
            $this->assertStringNotContainsString('X-Frame-Options', $waf_content);
            $this->assertStringNotContainsString('location = /favicon.ico', $waf_content);
            $this->assertStringContainsString('#include /etc/conductor/wafs/@@APPNAME@@.conf;', $vhost_content);
            $this->assertStringNotContainsString('    include /etc/conductor/wafs/@@APPNAME@@.conf;', $vhost_content);

            $protection_start = strpos($vhost_content, Conductor::PROTECTION_START_MARKER);
            $protection_end = strpos($vhost_content, Conductor::PROTECTION_END_MARKER);
            $waf_start = strpos($vhost_content, Conductor::WAF_START_MARKER);
            $this->assertNotFalse($protection_start);
            $this->assertNotFalse($protection_end);
            $this->assertNotFalse($waf_start);
            $this->assertLessThan($waf_start, $protection_start);
            $this->assertLessThan($waf_start, $protection_end);
            $between_blocks = substr($vhost_content, $protection_end, $waf_start - $protection_end);
            $this->assertLessThanOrEqual(4, substr_count($between_blocks, PHP_EOL));
        }

        $crawler_block = file_get_contents(__DIR__ . '/../configs/common/xcaler_community_search_engines.conf');
        $bot_block = file_get_contents(__DIR__ . '/../configs/common/xcaler_community_ai_bots.conf');
        $sql_injection_block = file_get_contents(__DIR__ . '/../configs/common/xcaler_community_sql_injection.conf');
        $path_traversal_block = file_get_contents(__DIR__ . '/../configs/common/xcaler_community_path_traversal.conf');
        $file_block = file_get_contents(__DIR__ . '/../configs/common/xcaler_community_common_paths.conf');
        $quiet_common_requests = file_get_contents(__DIR__ . '/../configs/common/conductor_quiet_common_requests.conf');
        $error_pages = file_get_contents(__DIR__ . '/../configs/common/conductor_error_pages.conf');
        $waf_error_pages = file_get_contents(__DIR__ . '/../configs/common/conductor_waf_error_pages.conf');
        $waf_error_page = file_get_contents(__DIR__ . '/../configs/common/templates/406.html.tpl');

        $this->assertStringContainsString('Googlebot', $crawler_block);
        $this->assertStringContainsString('Bingbot', $crawler_block);
        $this->assertStringContainsString('GPTBot', $bot_block);
        $this->assertStringContainsString('ClaudeBot', $bot_block);
        $this->assertStringContainsString('union', $sql_injection_block);
        $this->assertStringContainsString('information_schema', $sql_injection_block);
        $this->assertStringContainsString('etc/passwd', $path_traversal_block);
        $this->assertStringContainsString('%2e%2e', $path_traversal_block);
        $this->assertStringContainsString('\.\.\\\\', $path_traversal_block);
        $this->assertStringContainsString('wp-config.php', $file_block);
        $this->assertStringContainsString('node_modules', $file_block);
        $this->assertStringContainsString('well-known', $file_block);
        $this->assertStringContainsString('location = /favicon.ico', $quiet_common_requests);
        $this->assertStringContainsString('location = /robots.txt', $quiet_common_requests);
        foreach ([$crawler_block, $bot_block, $sql_injection_block, $path_traversal_block, $file_block] as $block) {
            $this->assertStringContainsString('return 406;', $block);
            preg_match_all('/~\* "((?:\\\\.|[^"\\\\])*)"/', $block, $matches);
            foreach ($matches[1] as $pattern) {
                $this->assertNginxRegexCompiles($pattern);
            }
        }
        preg_match('/~\* "((?:\\\\.|[^"\\\\])*)"/', $path_traversal_block, $path_matches);
        $path_regex = '~' . str_replace('~', '\~', $this->nginxQuotedRegexForPcre($path_matches[1])) . '~i';
        $this->assertSame(1, preg_match($path_regex, '../etc/passwd'));
        $this->assertSame(1, preg_match($path_regex, '..\\windows\\win.ini'));
        foreach ([401, 403, 404, 500] as $status_code) {
            $this->assertStringContainsString('error_page ' . $status_code . ' @conductor_error_' . $status_code . ';', $error_pages);
            $this->assertStringContainsString('try_files /.conductor/error_pages/' . $status_code . '.html @conductor_error_' . $status_code . '_shared;', $error_pages);
            $this->assertStringContainsString('try_files /' . $status_code . '.html =' . $status_code . ';', $error_pages);
        }
        $this->assertStringContainsString('root /var/conductor/error-pages;', $error_pages);
        $this->assertStringContainsString('error_page 406 @conductor_waf_406;', $waf_error_pages);
        $this->assertStringContainsString('try_files /.conductor/error_pages/406.html @conductor_waf_406_shared;', $waf_error_pages);
        $this->assertStringContainsString('try_files /406.html =406;', $waf_error_pages);
        $this->assertStringContainsString('root /var/conductor/error-pages;', $waf_error_pages);
        $this->assertStringContainsString('add_header X-Application-Id $conductor_application always;', $waf_error_pages);
        $this->assertStringContainsString('Request rejected.', $waf_error_page);
        $this->assertStringNotContainsString('<dt>Application</dt>', $waf_error_page);
        $this->assertStringNotContainsString('<dt>Check</dt>', $waf_error_page);
        $this->assertStringNotContainsString('@@APPNAME@@', $waf_error_page);
    }

    public function testProxyVhostUsesSharedProxyErrorPageInclude(): void
    {
        $proxy_vhost = file_get_contents(__DIR__ . '/../configs/common/templates/vhost_proxy.tpl');
        $proxy_error_pages = file_get_contents(__DIR__ . '/../configs/common/conductor_proxy_error_pages.conf');

        $this->assertStringContainsString(
            'include /etc/conductor/configs/common/conductor_proxy_error_pages.conf;',
            $proxy_vhost
        );
        $this->assertStringNotContainsString('error_page 502 /.502.html;', $proxy_vhost);
        $this->assertStringContainsString('error_page 502 @conductor_proxy_502;', $proxy_error_pages);
        $this->assertStringContainsString('error_page 503 @conductor_proxy_503;', $proxy_error_pages);
        $this->assertStringContainsString('error_page 504 @conductor_proxy_504;', $proxy_error_pages);
        $this->assertStringContainsString('try_files /.conductor/error_pages/502.html @conductor_proxy_502_shared;', $proxy_error_pages);
        $this->assertStringContainsString('try_files /502.html =502;', $proxy_error_pages);
        $this->assertStringContainsString('try_files /503.html =503;', $proxy_error_pages);
        $this->assertStringContainsString('try_files /504.html =504;', $proxy_error_pages);
        $this->assertStringContainsString('root /var/conductor/error-pages;', $proxy_error_pages);
        $this->assertStringContainsString(
            'add_header X-Application-Id $conductor_application always;',
            $proxy_error_pages
        );
    }

    public function testInstallersSeedSharedWafErrorPage(): void
    {
        foreach ([
            file_get_contents(__DIR__ . '/../scripts/install_debian_12.sh'),
            file_get_contents(__DIR__ . '/../scripts/install_debian_13.sh'),
        ] as $installer) {
            $this->assertStringContainsString('/var/conductor/error-pages', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/401.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/403.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/404.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/406.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/500.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/502.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/503.html', $installer);
            $this->assertStringContainsString('/var/conductor/error-pages/504.html', $installer);
        }

        $conductor_source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');
        $this->assertStringContainsString('ensureSharedWafErrorPage', $conductor_source);
        $this->assertStringContainsString('ensureSharedErrorPage($status_code)', $conductor_source);
        $this->assertStringContainsString('/.conductor/error_pages', $conductor_source);
        $this->assertStringNotContainsString('createApplicationErrorPage(406', $conductor_source);
    }

    public function testErrorPageTemplatesSitAboveVerticalCenter(): void
    {
        foreach ([401, 403, 404, 406, 500, 502, 503, 504] as $status_code) {
            $template = file_get_contents(__DIR__ . '/../configs/common/templates/' . $status_code . '.html.tpl');

            $this->assertStringContainsString('padding: clamp(72px, 14vh, 132px) 20px 48px;', $template);
            $this->assertStringContainsString('align-items: start;', $template);
            $this->assertStringNotContainsString('place-items: center;', $template);

            if ($status_code >= 400 && $status_code < 500 && $status_code !== 406) {
                $this->assertStringContainsString('--danger: #8a5a00;', $template);
                $this->assertStringContainsString('--danger-soft: #fff7d6;', $template);
                continue;
            }

            $this->assertStringContainsString('--danger: #c82333;', $template);
        }
    }

    public function testProxyCacheExampleUsesSharedCacheZone(): void
    {
        $nginx_common = file_get_contents(__DIR__ . '/../configs/common/conductor_nginx.conf');
        $proxy_vhost = file_get_contents(__DIR__ . '/../configs/common/templates/vhost_proxy.tpl');

        $this->assertStringContainsString('proxy_cache_path /var/conductor/cache/nginx-proxy', $nginx_common);
        $this->assertStringContainsString('keys_zone=conductor_proxy:32m', $nginx_common);
        $this->assertStringContainsString('#proxy_cache conductor_proxy;', $proxy_vhost);
    }

    public function testFail2BanJailsPostBanAndUnbanWebhooks(): void
    {
        $webhook_action = file_get_contents(__DIR__ . '/../configs/common/fail2ban/action.d/conductor-webhook.conf');
        $jails = file_get_contents(__DIR__ . '/../configs/common/fail2ban/jail.d/conductor-nginx.conf');
        $nginx_common = file_get_contents(__DIR__ . '/../configs/common/conductor_nginx.conf');
        $webhook_helper = file_get_contents(__DIR__ . '/../utils/fail2ban_webhook.sh');
        $installer = file_get_contents(__DIR__ . '/../utils/install_fail2ban_nftables.sh');

        $this->assertStringContainsString('url = http://127.0.0.1', $webhook_action);
        $this->assertStringContainsString('/etc/conductor/utils/fail2ban_webhook.sh ban', $webhook_action);
        $this->assertStringContainsString('/etc/conductor/utils/fail2ban_webhook.sh unban', $webhook_action);
        $this->assertStringContainsString('ban "<name>" "<ip>" "<bantime>" "<F-APPLICATION>" "<url>"', $webhook_action);
        $this->assertStringContainsString('unban "<name>" "<ip>" "" "" "<url>"', $webhook_action);
        $this->assertStringContainsString('$conductor_application $status', $nginx_common);
        $this->assertStringContainsString('"event"', $webhook_helper);
        $this->assertStringContainsString('"bantime"', $webhook_helper);
        $this->assertStringContainsString('"application"', $webhook_helper);
        $this->assertStringContainsString('url="${6:-http://127.0.0.1}"', $webhook_helper);
        $this->assertStringNotContainsString('"seclogs"', $webhook_helper);
        $this->assertStringContainsString('-H "Content-Type: application/json"', $webhook_helper);

        foreach ([
            'conductor-manual',
            'conductor-scanner',
            'conductor-4xx',
            'conductor-401',
            'conductor-403',
            'conductor-waf-violation',
            'conductor-geoip-block',
            'conductor-burst',
            'conductor-dos',
        ] as $action_name) {
            $this->assertStringContainsString('nftables-multiport[name=' . $action_name . ', port="http,https", protocol=tcp, chain=conductor-f2b-input, chain_hook=input]', $jails);
            $this->assertStringContainsString('nftables-multiport[name=' . $action_name . '-forward, port="http,https", protocol=tcp, chain=conductor-f2b-forward, chain_hook=forward]', $jails);
            $this->assertStringContainsString('conductor-webhook[name=' . $action_name . ']', $jails);
        }

        $this->assertStringNotContainsString('https://example.com/fail2ban-webhook', $jails);
        $this->assertStringContainsString('fail2ban nftables curl', $installer);
        $this->assertStringNotContainsString('fail2ban nftables logrotate curl', $installer);
        $this->assertStringContainsString('conductor waf {appname} --enable --auto-reload', $installer);
        $this->assertStringContainsString('conductor waf {appname} --disable --auto-reload', $installer);
        $this->assertStringNotContainsString('conductor protect {appname}', $installer);
    }

    public function testWafWebhookConfigureUpdatesFail2BanWebhookActionUrl(): void
    {
        $root = sys_get_temp_dir() . '/conductor-waf-webhook-' . uniqid();
        $action_directory = $root . '/fail2ban/action.d';
        mkdir($root);
        mkdir($root . '/fail2ban');
        mkdir($action_directory);

        $action_path = $action_directory . '/conductor-webhook.conf';
        file_put_contents($action_path, implode(PHP_EOL, [
            '[Definition]',
            '',
            'actionban = /etc/conductor/utils/fail2ban_webhook.sh ban "<name>" "<ip>" "<bantime>" "<F-APPLICATION>" "<url>" || true',
            '',
            '[Init]',
            '',
            'url = https://old.example.com/webhook',
            '',
        ]));

        $endpoint = 'https://n8n.example.com/webhook/8b4e7040-3746-4120-b317-50110f074a53';
        $conductor = new class($endpoint) extends Conductor {
            public array $calls = [];
            public array $lines = [];
            private string $endpoint;

            public function __construct($endpoint)
            {
                $this->endpoint = $endpoint;
            }

            public function getCommand($part, $default = false)
            {
                return [1 => 'waf', 2 => 'webhook'][$part] ?? $default;
            }

            public function getOption($name, $default = false)
            {
                return $name == 'configure' ? $this->endpoint : $default;
            }

            public function callWithOutput($command, &$output)
            {
                $this->calls[] = $command;
                $output = [];
                return 0;
            }

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };

        (new ReflectionClass(Conductor::class))->getProperty('conf')->setValue($conductor, (object) [
            'paths' => (object) [
                'fail2ban_actions' => $action_directory,
            ],
        ]);

        $conductor->wafControl();

        $this->assertStringContainsString('url = ' . $endpoint, file_get_contents($action_path));
        $this->assertContains('Updated Fail2Ban webhook endpoint: ' . $endpoint, $conductor->lines);
        $this->assertContains('systemctl restart fail2ban 2>&1', $conductor->calls);

        @unlink($action_path);
        @rmdir($action_directory);
        @rmdir($root . '/fail2ban');
        @rmdir($root);
    }

    public function testLetsEncryptWebhookHelperPostsJsonPayload(): void
    {
        $webhook_config = file_get_contents(__DIR__ . '/../configs/common/letsencrypt-webhook.conf');
        $webhook_helper = file_get_contents(__DIR__ . '/../utils/letsencrypt_webhook.sh');
        $renew_helper = file_get_contents(__DIR__ . '/../utils/certbot_renew.sh');
        $debian_config = file_get_contents(__DIR__ . '/../bin/conf/conductor.debian.template.json');
        $conductor_source = file_get_contents(__DIR__ . '/../bin/inc/Conductor.php');

        $this->assertStringContainsString('url = http://127.0.0.1', $webhook_config);
        $this->assertStringContainsString('__LETSENCRYPT_DEPLOY_HOOK__', $debian_config);
        $this->assertStringContainsString('/etc/conductor/utils/letsencrypt_webhook.sh deploy', $conductor_source);
        $this->assertStringContainsString('"event"', $webhook_helper);
        $this->assertStringContainsString('"app"', $webhook_helper);
        $this->assertStringContainsString('"lineage"', $webhook_helper);
        $this->assertStringContainsString('"domains"', $webhook_helper);
        $this->assertStringContainsString('-H "Content-Type: application/json"', $webhook_helper);
        $this->assertStringContainsString('/etc/conductor/utils/letsencrypt_webhook.sh renew', $renew_helper);
    }

    public function testFail2BanFiltersExtractClientIpFieldOnly(): void
    {
        foreach (glob(__DIR__ . '/../configs/common/fail2ban/filter.d/conductor-nginx-*.conf') as $filter_path) {
            $filter = file_get_contents($filter_path);

            $this->assertStringContainsString('(?:\S+T\S+\s+)?<HOST>\s+<F-APPLICATION>\S+</F-APPLICATION>\s+', $filter, basename($filter_path));
            $this->assertStringNotContainsString('(?:\S+\s+)?<HOST>', $filter, basename($filter_path));
        }
    }

    public function testCrowdSecInstallerFallsBackToGenericFirewallBouncerPackage(): void
    {
        $installer = file_get_contents(__DIR__ . '/../utils/install_crowdsec.sh');

        $this->assertStringContainsString('for package in crowdsec-firewall-bouncer-nftables crowdsec-firewall-bouncer', $installer);
        $this->assertStringContainsString('OFFICIAL_REPO_BOOTSTRAPPED="no"', $installer);
        $this->assertStringContainsString('OFFICIAL_REPO_BOOTSTRAPPED="yes"', $installer);
        $this->assertStringContainsString('No CrowdSec nftables firewall bouncer package was found. Checked crowdsec-firewall-bouncer-nftables and crowdsec-firewall-bouncer.', $installer);
    }

    public function testMainInstallersInstallConductorLogrotateRules(): void
    {
        $debian12 = file_get_contents(__DIR__ . '/../scripts/install_debian_12.sh');
        $debian13 = file_get_contents(__DIR__ . '/../scripts/install_debian_13.sh');
        $vhost_logrotate = file_get_contents(__DIR__ . '/../configs/common/logrotate/conductor-vhost-logs');

        foreach ([$debian12, $debian13] as $installer) {
            $this->assertStringContainsString('logrotate', $installer);
            $this->assertStringContainsString('/etc/conductor/configs/common/logrotate/* /etc/logrotate.d/', $installer);
        }

        $this->assertStringContainsString('/var/conductor/logs/*/access.log /var/conductor/logs/*/error.log', $vhost_logrotate);
        $this->assertStringContainsString('copytruncate', $vhost_logrotate);
        $this->assertStringContainsString('su www-data www-data', $vhost_logrotate);
    }

    public function testCompleteSuggestsCommandsOptionsAndApplicationNames(): void
    {
        $apps = sys_get_temp_dir() . '/conductor-apps-' . uniqid();
        $configs = sys_get_temp_dir() . '/conductor-configs-' . uniqid();
        mkdir($apps);
        mkdir($configs);
        mkdir($apps . '/alpha');
        file_put_contents($configs . '/bravo.conf', '');

        $conductor = new class(['conductor', '__complete', 1, 'conductor', 'e']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property = (new ReflectionClass(Conductor::class))->getProperty('conf');
        $property->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $apps,
                'appconfs' => $configs,
            ],
        ]);

        $conductor->complete();
        $this->assertContains('edit', $conductor->lines);
        $this->assertContains('enable', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 1, 'conductor', 's']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('stats', $conductor->lines);
        $this->assertContains('showkey', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 1, 'conductor', 'p']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertNotContains('protect', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 1, 'conductor', 'w']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('waf', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'stats', '--']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('--format=', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'new', '--t']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('--template=', $conductor->lines);
        $this->assertContains('--target=', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'auth', '--']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('--enable', $conductor->lines);
        $this->assertContains('--disable', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'letsencrypt', '--']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('--force-renew', $conductor->lines);
        $this->assertContains('--configure=', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'waf', '--']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('--enable', $conductor->lines);
        $this->assertContains('--disable', $conductor->lines);
        $this->assertContains('--auto-reload', $conductor->lines);
        $this->assertContains('--configure=', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'test', '--']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('--auto-reload', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'edit', '']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $apps,
                'appconfs' => $configs,
            ],
        ]);

        $conductor->complete();
        $this->assertContains('alpha', $conductor->lines);
        $this->assertContains('bravo', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'waf', '']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $apps,
                'appconfs' => $configs,
            ],
        ]);

        $conductor->complete();
        $this->assertContains('alpha', $conductor->lines);
        $this->assertContains('bravo', $conductor->lines);
        $this->assertContains('webhook', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'letsencrypt', '']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $apps,
                'appconfs' => $configs,
            ],
        ]);

        $conductor->complete();
        $this->assertContains('alpha', $conductor->lines);
        $this->assertContains('bravo', $conductor->lines);
        $this->assertContains('webhook', $conductor->lines);

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'showkey', '']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, (object) [
            'paths' => (object) [
                'apps' => $apps,
                'appconfs' => $configs,
            ],
        ]);

        $conductor->complete();
        $this->assertContains('alpha', $conductor->lines);
        $this->assertContains('bravo', $conductor->lines);

        @unlink($configs . '/bravo.conf');
        @rmdir($apps . '/alpha');
        @rmdir($apps);
        @rmdir($configs);

        $conductor = new class(['conductor', '__complete', 3, 'conductor', 'auth', 'alpha', '']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('set', $conductor->lines);
        $this->assertContains('delete', $conductor->lines);
    }

    public function testReplaceBetweenSectionsReplacesContentBetweenMarkers(): void
    {
        $file = "before\n# START\nold content\n# END\nafter";

        $result = $this->makeConductor()->replaceBetweenSections('# START', '# END', $file, "\nnew content\n");

        $this->assertSame("before\n# START\nnew content\n# END\nafter", $result);
    }

    public function testReplaceBetweenSectionsReplacesWholeFileWhenMarkersAreMissing(): void
    {
        $result = $this->makeConductor()->replaceBetweenSections(
            '# START',
            '# END',
            'just some content',
            ' replacement'
        );

        $this->assertSame(' replacement', $result);
    }

    public function testNginxConfigCommentTogglesKeepDirectiveAlignment(): void
    {
        $reflection = new ReflectionClass(Conductor::class);
        $comment = $reflection->getMethod('commentNginxConfigLine');
        $uncomment = $reflection->getMethod('uncommentNginxConfigLine');
        $conductor = $this->makeConductor();

        $this->assertSame('    #access_log /tmp/app.seclog conductor_security;', $comment->invoke($conductor, '    access_log /tmp/app.seclog conductor_security;'));
        $this->assertSame('    #access_log /tmp/app.seclog conductor_security;', $comment->invoke($conductor, '    #   access_log /tmp/app.seclog conductor_security;'));
        $this->assertSame('    access_log /tmp/app.seclog conductor_security;', $uncomment->invoke($conductor, '    #   access_log /tmp/app.seclog conductor_security;'));
        $this->assertSame('    ' . Conductor::WAF_START_MARKER, $comment->invoke($conductor, '    ' . Conductor::WAF_START_MARKER));
        $this->assertSame('    ' . Conductor::WAF_START_MARKER, $uncomment->invoke($conductor, '    ' . Conductor::WAF_START_MARKER));
    }

    public function testFormatDurationUsesDaysHoursAndMinutes(): void
    {
        $method = (new ReflectionClass(Conductor::class))->getMethod('formatDuration');

        $this->assertSame('0d 0h 0m', $method->invoke($this->makeConductor(), 59));
        $this->assertSame('1d 2h 3m', $method->invoke($this->makeConductor(), 93780));
        $this->assertSame('N/A', $method->invoke($this->makeConductor(), null));
    }

    public function testWholeSecondsRemovesDecimalValues(): void
    {
        $method = (new ReflectionClass(Conductor::class))->getMethod('wholeSeconds');

        $this->assertSame(123, $method->invoke($this->makeConductor(), 123.98));
        $this->assertNull($method->invoke($this->makeConductor(), null));
    }

    public function testParseNginxActiveSinceUptimeSecondsReadsServiceStatusOutput(): void
    {
        $method = (new ReflectionClass(Conductor::class))->getMethod('parseNginxActiveSinceUptimeSeconds');
        $status = <<<STATUS
- nginx.service - A high performance web server and a reverse proxy server
  Loaded: loaded (/usr/lib/systemd/system/nginx.service; enabled; preset: enabled)
  Active: active (running) since Wed 2026-07-15 21:19:06 UTC; 6min ago
STATUS;

        $now = strtotime('Wed 2026-07-15 21:25:06 UTC');

        $this->assertSame(360.0, $method->invoke($this->makeConductor(), $status, $now));
    }

    public function testParseNginxActiveSinceUptimeSecondsReadsSystemctlTimestamp(): void
    {
        $method = (new ReflectionClass(Conductor::class))->getMethod('parseNginxActiveSinceUptimeSeconds');
        $now = strtotime('Wed 2026-07-15 21:25:06 UTC');

        $this->assertSame(360.0, $method->invoke($this->makeConductor(), 'Wed 2026-07-15 21:19:06 UTC', $now));
        $this->assertNull($method->invoke($this->makeConductor(), 'n/a', $now));
    }

    public function testParseNginxStatusReturnsTrimmedStatusLines(): void
    {
        $method = (new ReflectionClass(Conductor::class))->getMethod('parseNginxStatus');

        $this->assertSame([
            'Active connections:   1',
            'Accepted connections: 10',
            'Handled connections:  10',
            'Requests:             20',
            'Reading:              0',
            'Writing:              1',
            'Waiting:              0',
        ], $method->invoke($this->makeConductor(), " Active connections: 1 \nserver accepts handled requests\n 10 10 20\nReading: 0 Writing: 1 Waiting: 0\n"));
    }

    public function testParseNginxStatusDataReturnsStructuredCounters(): void
    {
        $method = (new ReflectionClass(Conductor::class))->getMethod('parseNginxStatusData');

        $this->assertSame([
            'active_connections' => 1,
            'accepted_connections' => 10,
            'handled_connections' => 10,
            'requests' => 20,
            'reading' => 0,
            'writing' => 1,
            'waiting' => 0,
        ], $method->invoke($this->makeConductor(), "Active connections: 1\nserver accepts handled requests\n10 10 20\nReading: 0 Writing: 1 Waiting: 0\n"));
    }
}
