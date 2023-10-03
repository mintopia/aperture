<?php

namespace App\Services\Interfaces;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\Firewalls\OpnsenseBackendInterface;

interface FirewallBackendInterface
{
    /**
     * @param string $ip
     * @param string $description
     * @return $this
     * @throws BackendException
     */
    public function updateIp(string $ip, string $description): self;

    /**
     * @param string $ip
     * @return $this
     * @throws BackendException
     */
    public function removeIp(string $ip): self;

    /**
     * @param array<int, string> $hostnames
     * @return $this
     * @throws BackendException
     */
    public function addAllowedHostnames(array $hostnames): self;

    /**
     * @param string $ip
     * @return $this
     * @throws BackendException
     */
    public function limitIp(string $ip): self;

    /**
     * @param string $ip
     * @return $this
     * @throws BackendException
     */
    public function unlimitIp(string $ip): self;
}
