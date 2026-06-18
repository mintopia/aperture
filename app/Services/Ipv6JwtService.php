<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntegrationConfig;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class Ipv6JwtService
{
    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * Verify an RS256 JWT via JWKS and extract the IPv6 address from the sub claim.
     *
     * @throws InvalidArgumentException When sub is not a valid IPv6 address
     * @throws RuntimeException When JWKS fetch fails
     * @throws SignatureInvalidException When signature verification fails
     * @throws ExpiredException When JWT has expired
     */
    public function verifyAndExtract(string $jwt, string $jwksUrl): string
    {
        $jwksData = $this->fetchJwks($jwksUrl);
        $keys = JWK::parseKeySet($jwksData);
        $decoded = JWT::decode($jwt, $keys);

        $this->validateAudience($decoded);
        $this->validateIssuer($decoded);

        $ipv6 = $decoded->sub ?? '';
        if (! filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new InvalidArgumentException('JWT sub claim is not a valid IPv6 address: '.$ipv6);
        }

        return $ipv6;
    }

    private function validateAudience(object $decoded): void
    {
        /** @var string|null $expectedAudience */
        $expectedAudience = IntegrationConfig::getValue('ipv6', 'jwt_audience');
        if ($expectedAudience === null || $expectedAudience === '') {
            return;
        }

        $actualAudience = $decoded->aud ?? null;
        if ($actualAudience !== $expectedAudience) {
            throw new InvalidArgumentException(
                sprintf('JWT audience mismatch: expected "%s", got "%s"', $expectedAudience, (string) ($actualAudience ?? ''))
            );
        }
    }

    private function validateIssuer(object $decoded): void
    {
        /** @var string|null $expectedIssuer */
        $expectedIssuer = IntegrationConfig::getValue('ipv6', 'jwt_issuer');
        if ($expectedIssuer === null || $expectedIssuer === '') {
            return;
        }

        $actualIssuer = $decoded->iss ?? null;
        if ($actualIssuer !== $expectedIssuer) {
            throw new InvalidArgumentException(
                sprintf('JWT issuer mismatch: expected "%s", got "%s"', $expectedIssuer, (string) ($actualIssuer ?? ''))
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $jwksUrl): array
    {
        $cacheKey = 'ipv6_jwks:'.md5($jwksUrl);

        return $this->cache->remember($cacheKey, 3600, function () use ($jwksUrl): array {
            $response = Http::timeout(10)->get($jwksUrl);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf('Failed to fetch JWKS from %s: HTTP %d', $jwksUrl, $response->status()));
            }

            return $response->json();
        });
    }
}
