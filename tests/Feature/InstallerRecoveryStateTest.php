<?php

namespace Tests\Feature;

use Beike\Installer\Helpers\InstallationStateManager;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;

class InstallerRecoveryStateTest extends TestCase
{
    private string $originalStoragePath;

    private string $temporaryStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->originalStoragePath  = $app->storagePath();
        $this->temporaryStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-installer-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryStoragePath, 0775, true);
        $app->useStoragePath($this->temporaryStoragePath);
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        $stateFile = $this->temporaryStoragePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'installer-state.json';
        if (is_file($stateFile)) {
            unlink($stateFile);
        }
        if (is_file($stateFile . '.lock')) {
            unlink($stateFile . '.lock');
        }
        @rmdir($this->temporaryStoragePath . DIRECTORY_SEPARATOR . 'app');
        @rmdir($this->temporaryStoragePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_installation_stage_can_resume_without_persisting_credentials(): void
    {
        $manager   = new InstallationStateManager;
        $signature = hash('sha256', 'verified-database-connection');

        self::assertFalse($manager->matches($signature));
        self::assertFalse($manager->hasReached($signature, 'database_validated'));
        self::assertTrue($manager->write($signature, 'database_validated'));
        self::assertTrue($manager->matches($signature));
        self::assertTrue($manager->hasReached($signature, 'database_validated'));
        self::assertFalse($manager->hasReached($signature, 'database_migrated'));
        self::assertFalse($manager->matches(hash('sha256', 'different-database')));
        self::assertSame('database_validated', $manager->read()['stage']);

        $contents = file_get_contents($this->temporaryStoragePath . '/app/installer-state.json');
        self::assertStringNotContainsString('password', (string) $contents);
        self::assertStringNotContainsString('username', (string) $contents);

        self::assertTrue($manager->write($signature, 'schema_migrated'));
        self::assertSame('schema_migrated', $manager->read()['stage']);
        self::assertTrue($manager->hasReached($signature, 'schema_migrated'));
        self::assertFalse($manager->hasReached($signature, 'database_migrated'));

        self::assertTrue($manager->write($signature, 'database_migrated'));
        self::assertSame('database_migrated', $manager->read()['stage']);
        self::assertTrue($manager->hasReached($signature, 'database_validated'));
        self::assertTrue($manager->hasReached($signature, 'database_migrated'));
        self::assertFalse($manager->hasReached(hash('sha256', 'different-database'), 'database_validated'));

        self::assertTrue($manager->write($signature, 'completed', [
            'admin_email'    => 'admin@example.com',
            'admin_password' => 'must-not-be-written',
            'saas'           => true,
            'base_domain'    => 'shops.example.com',
            'dns_targets'    => ['192.0.2.10'],
        ]));
        self::assertSame('admin@example.com', $manager->completion($signature)['admin_email']);
        self::assertArrayNotHasKey('admin_password', $manager->completion($signature));
        self::assertStringNotContainsString('must-not-be-written', (string) file_get_contents($this->temporaryStoragePath . '/app/installer-state.json'));
        self::assertTrue($manager->write($signature, 'database_validated'));
        self::assertSame('completed', $manager->read()['stage'], 'Recovery state must never regress to an earlier stage.');
        self::assertTrue($manager->hasReached($signature, 'saas_registered'));
        self::assertFalse($manager->hasReached($signature, 'not-a-stage'));

        $manager->clear();
        self::assertSame([], $manager->read());
    }

    public function test_recovery_state_does_not_expire_while_installation_is_incomplete(): void
    {
        $signature = hash('sha256', 'long-running-maintenance');
        $statePath = $this->temporaryStoragePath . '/app/installer-state.json';
        mkdir(dirname($statePath), 0775, true);
        file_put_contents($statePath, json_encode([
            'database_signature' => $signature,
            'stage'              => 'database_migrated',
            'updated_at'         => '2024-01-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $manager = new InstallationStateManager;

        self::assertTrue($manager->matches($signature));
        self::assertTrue($manager->hasReached($signature, 'database_migrated'));
    }
}
