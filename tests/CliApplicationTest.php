<?php

namespace Tests;

use CliApplication;
use PHPUnit\Framework\TestCase;

final class CliApplicationTest extends TestCase
{
    public function testParsesCommandsOptionsAndFlags(): void
    {
        $args = [
            'conductor', 'new', 'myapp', '--fqdn=example.com', '--environment=production', '-v', 'trailing',
        ];
        $app = new CliApplication($args);

        $this->assertSame(['conductor', 'new', 'myapp', 'trailing'], $app->commands());
        $this->assertSame(['fqdn' => 'example.com', 'environment' => 'production'], $app->options());
        $this->assertSame(['v'], $app->flags());
        $this->assertSame($args, $app->rawArgs());
    }

    public function testCombinedShortFlagsAreSplitIntoIndividualCharacters(): void
    {
        $app = new CliApplication(['conductor', '-abc']);

        $this->assertSame(['a', 'b', 'c'], $app->flags());
    }

    public function testIsFlagSet(): void
    {
        $app = new CliApplication(['conductor', '-f']);

        $this->assertTrue($app->isFlagSet('f'));
        $this->assertFalse($app->isFlagSet('x'));
    }

    public function testLongFlagWithoutEqualsIsTreatedAsABooleanFlag(): void
    {
        $app = new CliApplication(['conductor', '--force']);

        $this->assertTrue($app->isFlagSet('force'));
        $this->assertSame([], $app->options());
    }

    public function testGetOptionReturnsDefaultWhenMissing(): void
    {
        $app = new CliApplication(['conductor']);

        $this->assertFalse($app->getOption('missing'));
        $this->assertSame('fallback', $app->getOption('missing', 'fallback'));
    }

    public function testGetCommandReturnsDefaultWhenMissing(): void
    {
        $app = new CliApplication(['conductor', 'new']);

        $this->assertSame('new', $app->getCommand(1));
        $this->assertFalse($app->getCommand(5));
        $this->assertSame('none', $app->getCommand(5, 'none'));
    }

    public function testArgumentsAreEmptyAsTheEndOfOptionsMarkerIsNeverSet(): void
    {
        // NOTE: paramBuilder() never flips $endofoptions to true, so everything
        // that isn't a flag/option is always classified as a command.
        $app = new CliApplication(['conductor', 'list']);

        $this->assertSame([], $app->arguments());
    }
}
