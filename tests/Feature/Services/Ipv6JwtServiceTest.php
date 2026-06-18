<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\IntegrationConfig;
use App\Services\Ipv6JwtService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

class Ipv6JwtServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $jwksUrl = 'https://ipv6.example.com/.well-known/jwks.json';

    private OpenSSLAsymmetricKey $privateKey;

    private string $kid = 'test-key-1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        assert($keyPair instanceof OpenSSLAsymmetricKey);
        openssl_pkey_export($keyPair, $privatePem);
        $privateKey = openssl_pkey_get_private((string) $privatePem);
        assert($privateKey instanceof OpenSSLAsymmetricKey);
        $this->privateKey = $privateKey;
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function makeJwks(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);
        assert(is_array($details));
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => $this->kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $n,
                'e' => $e,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $extraClaims
     */
    private function makeJwt(string $sub, ?string $kid = null, array $extraClaims = []): string
    {
        $payload = array_merge(
            ['sub' => $sub, 'iat' => time(), 'exp' => time() + 300],
            $extraClaims,
        );

        return JWT::encode(
            $payload,
            $this->privateKey,
            'RS256',
            $kid ?? $this->kid,
        );
    }

    public function test_verifies_valid_jwt_and_extracts_ipv6(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $result = $service->verifyAndExtract($jwt, $this->jwksUrl);

        $this->assertSame('2001:db8::1', $result);
    }

    public function test_rejects_jwt_with_ipv4_in_sub(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('192.168.1.1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid IPv6');

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_rejects_jwt_with_invalid_signature(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);

        // Create a JWT signed with a different key
        $otherKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        assert($otherKey instanceof OpenSSLAsymmetricKey);
        openssl_pkey_export($otherKey, $otherPem);
        $otherPrivate = openssl_pkey_get_private((string) $otherPem);
        assert($otherPrivate instanceof OpenSSLAsymmetricKey);
        $badJwt = JWT::encode(
            ['sub' => '2001:db8::1', 'iat' => time(), 'exp' => time() + 300],
            $otherPrivate,
            'RS256',
            $this->kid,
        );

        $this->expectException(SignatureInvalidException::class);

        $service->verifyAndExtract($badJwt, $this->jwksUrl);
    }

    public function test_caches_jwks_response(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);

        $service->verifyAndExtract($this->makeJwt('2001:db8::1'), $this->jwksUrl);
        $service->verifyAndExtract($this->makeJwt('2001:db8::2'), $this->jwksUrl);

        Http::assertSentCount(1);
    }

    public function test_rejects_expired_jwt(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);

        $expiredJwt = JWT::encode(
            ['sub' => '2001:db8::1', 'iat' => time() - 600, 'exp' => time() - 300],
            $this->privateKey,
            'RS256',
            $this->kid,
        );

        $this->expectException(ExpiredException::class);

        $service->verifyAndExtract($expiredJwt, $this->jwksUrl);
    }

    public function test_throws_on_jwks_fetch_failure(): void
    {
        Http::fake([$this->jwksUrl => Http::response('Server Error', 500)]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch JWKS');

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_accepts_jwt_with_correct_audience_when_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_audience', 'aperture');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1', extraClaims: ['aud' => 'aperture']);

        $result = $service->verifyAndExtract($jwt, $this->jwksUrl);

        $this->assertSame('2001:db8::1', $result);
    }

    public function test_rejects_jwt_with_wrong_audience_when_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_audience', 'aperture');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1', extraClaims: ['aud' => 'wrong-audience']);

        $this->expectException(InvalidArgumentException::class);

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_rejects_jwt_with_missing_audience_when_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_audience', 'aperture');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $this->expectException(InvalidArgumentException::class);

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_accepts_jwt_with_correct_issuer_when_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_issuer', 'borealis');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1', extraClaims: ['iss' => 'borealis']);

        $result = $service->verifyAndExtract($jwt, $this->jwksUrl);

        $this->assertSame('2001:db8::1', $result);
    }

    public function test_rejects_jwt_with_wrong_issuer_when_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_issuer', 'borealis');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1', extraClaims: ['iss' => 'wrong-issuer']);

        $this->expectException(InvalidArgumentException::class);

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_rejects_jwt_with_missing_issuer_when_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_issuer', 'borealis');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $this->expectException(InvalidArgumentException::class);

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_accepts_jwt_without_aud_iss_when_not_configured(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $result = $service->verifyAndExtract($jwt, $this->jwksUrl);

        $this->assertSame('2001:db8::1', $result);
    }

    public function test_accepts_jwt_with_both_correct_audience_and_issuer(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_audience', 'aperture');
        IntegrationConfig::setValue('ipv6', 'jwt_issuer', 'borealis');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1', extraClaims: ['aud' => 'aperture', 'iss' => 'borealis']);

        $result = $service->verifyAndExtract($jwt, $this->jwksUrl);

        $this->assertSame('2001:db8::1', $result);
    }

    public function test_rejects_jwt_with_correct_audience_but_wrong_issuer(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);
        IntegrationConfig::setValue('ipv6', 'jwt_audience', 'aperture');
        IntegrationConfig::setValue('ipv6', 'jwt_issuer', 'borealis');

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1', extraClaims: ['aud' => 'aperture', 'iss' => 'wrong']);

        $this->expectException(InvalidArgumentException::class);

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }
}
