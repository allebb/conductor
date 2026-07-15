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
                'auth' => $auth,
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
            '    #auth_basic_user_file /etc/conductor/auth/.htpasswd_myapp;',
            '    ' . Conductor::AUTH_END_MARKER,
            '}',
        ]));

        $conductor = new class extends Conductor {
            public function __construct()
            {
            }

            public function callWithExitCode($command)
            {
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
            'paths' => (object) [
                'appconfs' => $configs,
                'auth' => $auth,
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
        @unlink($configs . '/bravo.conf');
        @rmdir($apps . '/alpha');
        @rmdir($apps);
        @rmdir($configs);

        $this->assertContains('alpha', $conductor->lines);
        $this->assertContains('bravo', $conductor->lines);

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
}
