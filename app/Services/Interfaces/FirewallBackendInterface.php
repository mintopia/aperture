<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\ValueObjects\ReconcileResult;

interface FirewallBackendInterface
{
    /**
     * @return $this
     *
     * @throws BackendException
     */
    public function updateIp(string $ip, string $description): self;

    /**
     * @return $this
     *
     * @throws BackendException
     */
    public function removeIp(string $ip): self;

    /**
     * @param  array<int, string>  $hostnames
     * @return $this
     *
     * @throws BackendException
     */
    public function addAllowedHostnames(array $hostnames): self;

    /**
     * @return $this
     *
     * @throws BackendException
     */
    public function limitIp(string $ip): self;

    /**
     * @return $this
     *
     * @throws BackendException
     */
    public function unlimitIp(string $ip): self;

    /**
     * Reconcile internet access: sync captive portal sessions with desired IpAddress state.
     */
    public function reconcileInternet(bool $dryRun = false): ReconcileResult;

    /**
     * Reconcile rate limits: sync traffic shaper rules with desired IpAddress state.
     */
    public function reconcileRateLimits(bool $dryRun = false): ReconcileResult;
}
