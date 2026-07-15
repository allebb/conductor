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
        $property->setAccessible(true);
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

        $conductor = $this->makeConductorWithConfig((object) [
            'binaries' => (object) [
                'certbot' => $binary,
                'mysql' => '/no/such/mysql',
            ],
        ]);

        ob_start();
        $conductor->versions();
        $output = ob_get_clean();
        @unlink($binary);

        $this->assertStringContainsString('Component', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertMatchesRegularExpression('/CertBot\s+1\.2\.3/', $output);
        $this->assertMatchesRegularExpression('/MySQL\s+N\/A/', $output);
        $this->assertMatchesRegularExpression('/Crowdsec\s+N\/A/', $output);
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
