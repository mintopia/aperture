<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SwitchConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchConfig>
 */
class SwitchConfigFactory extends Factory
{
    protected $model = SwitchConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word().' Switch',
            'hostname' => fake()->unique()->domainName(),
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'password123',
            'enable_password' => 'enable123',
            'enabled' => true,
            'port' => 22,
            'timeout' => 5,
            'timezone' => 'UTC',
        ];
    }

    public function withPrivateKey(string $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n"): static
    {
        return $this->state(fn (): array => ['password' => null, 'private_key' => $key]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
