<?php

namespace Tests;

use EnvHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvHandlerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'conductor_env_');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testLoadThrowsWhenFileIsMissing(): void
    {
        unlink($this->file);
        $handler = new EnvHandler($this->file);

        $this->expectException(RuntimeException::class);
        $handler->load();
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        file_put_contents($this->file, 'not json');
        $handler = new EnvHandler($this->file);

        $this->expectException(RuntimeException::class);
        $handler->load();
    }

    public function testLoadPopulatesVars(): void
    {
        file_put_contents($this->file, json_encode(['APP_ENV' => 'production']));
        $handler = new EnvHandler($this->file);
        $handler->load();

        $this->assertSame(['APP_ENV' => 'production'], $handler->all());
        $this->assertSame('production', $handler->get('APP_ENV'));
        $this->assertNull($handler->get('MISSING'));
        $this->assertSame('default', $handler->get('MISSING', 'default'));
    }

    public function testPushAddsANewValue(): void
    {
        file_put_contents($this->file, json_encode(['APP_ENV' => 'production']));
        $handler = new EnvHandler($this->file);
        $handler->load();

        $handler->push('DB_HOST', 'localhost');

        $this->assertSame(['APP_ENV' => 'production', 'DB_HOST' => 'localhost'], $handler->all());
    }

    public function testPushUpdatesAnExistingValue(): void
    {
        file_put_contents($this->file, json_encode(['APP_ENV' => 'production']));
        $handler = new EnvHandler($this->file);
        $handler->load();

        $handler->push('APP_ENV', 'staging');

        $this->assertSame(['APP_ENV' => 'staging'], $handler->all());
    }

    public function testRemoveDeletesAnExistingKey(): void
    {
        file_put_contents($this->file, json_encode(['APP_ENV' => 'production', 'DB_HOST' => 'localhost']));
        $handler = new EnvHandler($this->file);
        $handler->load();

        $handler->remove('DB_HOST');

        $this->assertSame(['APP_ENV' => 'production'], $handler->all());
    }

    public function testRemoveIsANoOpForAnUnknownKey(): void
    {
        file_put_contents($this->file, json_encode(['APP_ENV' => 'production']));
        $handler = new EnvHandler($this->file);
        $handler->load();

        $handler->remove('MISSING');

        $this->assertSame(['APP_ENV' => 'production'], $handler->all());
    }

    public function testSavePersistsChangesToFile(): void
    {
        file_put_contents($this->file, json_encode(['APP_ENV' => 'production']));
        $handler = new EnvHandler($this->file);
        $handler->load();
        $handler->push('DB_HOST', 'localhost');
        $handler->save();

        $reloaded = new EnvHandler($this->file);
        $reloaded->load();

        $this->assertSame(['APP_ENV' => 'production', 'DB_HOST' => 'localhost'], $reloaded->all());
    }
}
