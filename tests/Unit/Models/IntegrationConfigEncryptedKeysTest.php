<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IntegrationConfig;
use Tests\TestCase;

class IntegrationConfigEncryptedKeysTest extends TestCase
{
    public function test_encrypted_keys_constant_exists(): void
    {
        $this->assertTrue(defined(IntegrationConfig::class.'::ENCRYPTED_KEYS'));
    }

    public function test_contains_api_key(): void
    {
        $this->assertContains('api_key', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_contains_password(): void
    {
        $this->assertContains('password', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_contains_secret(): void
    {
        $this->assertContains('secret', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_contains_key(): void
    {
        $this->assertContains('key', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_contains_client_secret(): void
    {
        $this->assertContains('client_secret', IntegrationConfig::ENCRYPTED_KEYS);
    }
}
