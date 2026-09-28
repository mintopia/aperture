<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\Null\NullCaptivePortal;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullDnsFiltering;
use App\Services\Null\NullIpBandwidth;
use App\Services\Null\NullIpMacResolver;
use App\Services\Null\NullPortBandwidth;
use App\Services\Null\NullPortErrors;
use App\Services\Null\NullPortMac;
use App\Services\Null\NullRateLimiter;
use Illuminate\Contracts\Foundation\Application;

final class CapabilityResolver
{
    /** @var array<string, string>|null */
    private ?array $assignments = null;

    /**
     * @param  list<IntegrationBootstrapper>  $bootstrappers
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $bootstrappers,
    ) {}

    /**
     * @return array<string, array{interface: class-string, null: class-string}>
     */
    public static function contracts(): array
    {
        return [
            Capability::CaptivePortal->value => ['interface' => CaptivePortalInterface::class, 'null' => NullCaptivePortal::class],
            Capability::RateLimiting->value => ['interface' => RateLimitingInterface::class, 'null' => NullRateLimiter::class],
            Capability::Dhcp->value => ['interface' => DhcpInterface::class, 'null' => NullDhcpService::class],
            Capability::DnsFiltering->value => ['interface' => DnsFilteringInterface::class, 'null' => NullDnsFiltering::class],
            Capability::IpBandwidth->value => ['interface' => IpBandwidthInterface::class, 'null' => NullIpBandwidth::class],
            Capability::PortBandwidth->value => ['interface' => PortBandwidthInterface::class, 'null' => NullPortBandwidth::class],
            Capability::PortErrors->value => ['interface' => PortErrorsInterface::class, 'null' => NullPortErrors::class],
            Capability::IpMac->value => ['interface' => IpMacResolverInterface::class, 'null' => NullIpMacResolver::class],
            Capability::PortMac->value => ['interface' => PortMacInterface::class, 'null' => NullPortMac::class],
        ];
    }

    public function resolve(string $capability): object
    {
        $integration = $this->assignments()[$capability] ?? null;

        if ($integration !== null) {
            foreach ($this->bootstrappers as $bootstrapper) {
                if ($bootstrapper->integration()->value !== $integration) {
                    continue;
                }

                $factory = $bootstrapper->providers()[$capability] ?? null;
                $instance = $factory?->__invoke($this->app);

                if ($instance !== null) {
                    return $instance;
                }
            }
        }

        return $this->app->make(self::contracts()[$capability]['null']);
    }

    /**
     * @return array<string, string>
     */
    private function assignments(): array
    {
        return $this->assignments ??= InstallGuard::tolerateMissingTable(
            fn (): array => CapabilityAssignment::query()->get()->mapWithKeys(fn (CapabilityAssignment $a): array => [$a->capability->value => $a->integration])->all(),
            [],
        );
    }
}
