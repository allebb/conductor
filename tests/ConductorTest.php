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
        $this->assertMatchesRegularExpression('/Crowdsec\s+N\/A/', $output);
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
        mkdir($root);
        mkdir($configs);
        mkdir($waf);

        $config = $configs . '/myapp.conf';
        file_put_contents($config, implode(PHP_EOL, [
            'server {',
            '    ' . Conductor::WAF_START_MARKER,
            '    include /etc/conductor/wafs/myapp.conf;',
            '    ' . Conductor::WAF_END_MARKER,
            '}',
        ]));
        file_put_contents($waf . '/myapp.conf', '# waf');

        $conductor = new class extends Conductor {
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
                'wafs' => $waf,
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
        $this->assertStringContainsString('    include /etc/conductor/wafs/myapp.conf;', file_get_contents($config));

        @unlink($waf . '/myapp.conf');
        @unlink($config);
        @rmdir($waf);
        @rmdir($configs);
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

        @unlink($config);
        @rmdir($configs);
        @rmdir($root);
    }

    public function testApplicationWafConfigIsCreatedFromTemplate(): void
    {
        $root = sys_get_temp_dir() . '/conductor-waf-template-' . uniqid();
        $templates = $root . '/templates';
        $waf = $root . '/waf';
        mkdir($root);
        mkdir($templates);
        mkdir($waf);

        file_put_contents($templates . '/waf_html.tpl', "location = /test-@@APPNAME@@ { return 204; }\n");

        $conductor = $this->makeConductorWithConfig((object) [
            'paths' => (object) [
                'templates' => $root,
                'wafs' => $waf,
            ],
        ]);
        (new ReflectionClass(Conductor::class))->getProperty('appname')->setValue($conductor, 'myapp');

        $method = (new ReflectionClass(Conductor::class))->getMethod('createApplicationWafConfig');
        $method->invoke($conductor, 'html', ['@@APPNAME@@' => 'myapp']);

        $this->assertSame("location = /test-myapp { return 204; }\n", file_get_contents($waf . '/myapp.conf'));

        @unlink($waf . '/myapp.conf');
        @unlink($templates . '/waf_html.tpl');
        @rmdir($waf);
        @rmdir($templates);
        @rmdir($root);
    }

    public function testDumpApplicationConfigWritesRawVirtualhostOrWafConfig(): void
    {
        $root = sys_get_temp_dir() . '/conductor-dump-config-' . uniqid();
        $configs = $root . '/configs';
        $waf = $root . '/waf';
        mkdir($root);
        mkdir($configs);
        mkdir($waf);

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

    public function testDefaultWafTemplatesIncludeSharedBotBlocks(): void
    {
        foreach (['html', 'laravel', 'proxy', 'wordpress'] as $template) {
            $waf_content = file_get_contents(__DIR__ . '/../configs/common/templates/waf_' . $template . '.tpl');
            $vhost_content = file_get_contents(__DIR__ . '/../configs/common/templates/vhost_' . $template . '.tpl');

            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/block_common_crawlers.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/block_common_bots.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/block_common_sql_injection.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/block_common_path_traversal.conf;',
                $waf_content
            );
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/block_common_files.conf;',
                $waf_content
            );
            $this->assertStringContainsString('error_page 406 /.406.html;', $waf_content);
            $this->assertStringNotContainsString('error_page 406 /.406.html;', $vhost_content);
            $this->assertStringContainsString('X-Frame-Options', $vhost_content);
            $this->assertStringContainsString(
                'include /etc/conductor/configs/common/conductor_quiet_common_requests.conf;',
                $vhost_content
            );
            $this->assertStringNotContainsString('X-Frame-Options', $waf_content);
            $this->assertStringNotContainsString('location = /favicon.ico', $waf_content);
            $this->assertStringContainsString('#include /etc/conductor/wafs/@@APPNAME@@.conf;', $vhost_content);
            $this->assertStringNotContainsString('    include /etc/conductor/wafs/@@APPNAME@@.conf;', $vhost_content);
        }

        $crawler_block = file_get_contents(__DIR__ . '/../configs/common/block_common_crawlers.conf');
        $bot_block = file_get_contents(__DIR__ . '/../configs/common/block_common_bots.conf');
        $sql_injection_block = file_get_contents(__DIR__ . '/../configs/common/block_common_sql_injection.conf');
        $path_traversal_block = file_get_contents(__DIR__ . '/../configs/common/block_common_path_traversal.conf');
        $file_block = file_get_contents(__DIR__ . '/../configs/common/block_common_files.conf');
        $quiet_common_requests = file_get_contents(__DIR__ . '/../configs/common/conductor_quiet_common_requests.conf');
        $waf_error_page = file_get_contents(__DIR__ . '/../configs/common/templates/406.html.tpl');

        $this->assertStringContainsString('Googlebot', $crawler_block);
        $this->assertStringContainsString('Bingbot', $crawler_block);
        $this->assertStringContainsString('GPTBot', $bot_block);
        $this->assertStringContainsString('ClaudeBot', $bot_block);
        $this->assertStringContainsString('union', $sql_injection_block);
        $this->assertStringContainsString('information_schema', $sql_injection_block);
        $this->assertStringContainsString('etc/passwd', $path_traversal_block);
        $this->assertStringContainsString('%2e%2e', $path_traversal_block);
        $this->assertStringContainsString('wp-config.php', $file_block);
        $this->assertStringContainsString('node_modules', $file_block);
        $this->assertStringContainsString('well-known', $file_block);
        $this->assertStringContainsString('location = /favicon.ico', $quiet_common_requests);
        $this->assertStringContainsString('location = /robots.txt', $quiet_common_requests);
        foreach ([$crawler_block, $bot_block, $sql_injection_block, $path_traversal_block, $file_block] as $block) {
            $this->assertStringContainsString('return 406;', $block);
        }
        $this->assertStringContainsString('Request rejected.', $waf_error_page);
        $this->assertStringContainsString('@@APPNAME@@', $waf_error_page);
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
        $this->assertStringContainsString('error_page 502 /.502.html;', $proxy_error_pages);
        $this->assertStringContainsString('error_page 503 /.503.html;', $proxy_error_pages);
        $this->assertStringContainsString('error_page 504 /.504.html;', $proxy_error_pages);
    }

    public function testProxyCacheExampleUsesSharedCacheZone(): void
    {
        $nginx_common = file_get_contents(__DIR__ . '/../configs/common/conductor_nginx.conf');
        $proxy_vhost = file_get_contents(__DIR__ . '/../configs/common/templates/vhost_proxy.tpl');

        $this->assertStringContainsString('proxy_cache_path /var/conductor/cache/nginx-proxy', $nginx_common);
        $this->assertStringContainsString('keys_zone=conductor_proxy:32m', $nginx_common);
        $this->assertStringContainsString('#proxy_cache conductor_proxy;', $proxy_vhost);
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

        $conductor = new class(['conductor', '__complete', 1, 'conductor', 'p']) extends Conductor {
            public array $lines = [];

            public function writeln($line = '')
            {
                $this->lines[] = $line;
            }
        };
        $property->setValue($conductor, new \stdClass());

        $conductor->complete();
        $this->assertContains('protect', $conductor->lines);

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

        $conductor = new class(['conductor', '__complete', 2, 'conductor', 'protect', '--']) extends Conductor {
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
