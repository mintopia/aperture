<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use Illuminate\Support\Collection;

readonly class SnoopingFetchResult
{
    /**
     * @param  Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}>  $bindings
     */
    private function __construct(
        public SnoopingFetchStatus $status,
        public Collection $bindings,
    ) {}

    /**
     * @param  Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}>  $bindings
     */
    public static function fetched(Collection $bindings): self
    {
        return new self(SnoopingFetchStatus::Fetched, $bindings);
    }

    public static function unsupported(): self
    {
        return new self(SnoopingFetchStatus::Unsupported, collect());
    }

    public static function failed(): self
    {
        return new self(SnoopingFetchStatus::Failed, collect());
    }

    public function wasFetched(): bool
    {
        return $this->status === SnoopingFetchStatus::Fetched;
    }
}
