<?php

namespace Tests\Unit\Services\SshProxy;

use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\SshProxyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SshProxyClientTest extends TestCase
{
    protected function createClientWithFakes(array $responses): SshProxyClient
    {
        Http::fake(['*' => Http::sequence($responses)]);

        return new SshProxyClient('http://localhost:8022', 'test-key');
    }

    public function test_execute_sends_post_with_correct_payload(): void
    {
        $expectedResponse = [
            'success' => true,
            'output' => [
                ['command' => 'show version', 'output' => 'OPNsense 23.7'],
            ],
        ];

        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode($expectedResponse), 200),
        ]);

        $result = $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password123',
            [['command' => 'show version']],
        );

        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertInstanceOf(CommandOutput::class, $result->output[0]);
        $this->assertSame('show version', $result->output[0]->command);
        $this->assertSame('OPNsense 23.7', $result->output[0]->output);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://localhost:8022/execute'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['hostname'] === '192.168.1.1'
            && $request['username'] === 'admin'
            && $request['password'] === 'password123'
            && $request['commands'] === [['command' => 'show version']]
            && $request['port'] === 22
            && $request['channel'] === 'commands');
    }

    public function test_execute_sends_matcher_objects_and_sensitive_flag_verbatim(): void
    {
        $proxyClient = $this->createClientWithFakes([Http::response(['success' => true, 'output' => []], 200)]);

        $commands = [
            ['command' => 'en', 'if' => ['type' => 'regex', 'value' => '^{prompt}>\s*$'], 'expect' => ['type' => 'literal', 'value' => 'Password:']],
            ['command' => 'secret', 'sensitive' => true, 'if' => ['type' => 'literal', 'value' => 'Password:'], 'expect' => ['type' => 'regex', 'value' => '^{prompt}#\s*$']],
            ['command' => 'show version', 'expect' => ['type' => 'regex', 'value' => '^{prompt}#\s*$']],
        ];

        $proxyClient->execute('h', 'u', 'p', $commands);

        Http::assertSent(fn (Request $request): bool => $request['commands'] === $commands
            && ! array_key_exists('sensitive', $request['commands'][0])
            && $request['commands'][1]['sensitive']);
    }

    public function test_execute_uses_password_auth_method_without_private_key(): void
    {
        $proxyClient = $this->createClientWithFakes([Http::response(['success' => true, 'output' => []], 200)]);

        $proxyClient->execute('h', 'u', 'p', []);

        Http::assertSent(fn (Request $request): bool => $request['auth_method'] === 'password' && $request['private_key'] === '');
    }

    public function test_execute_uses_private_key_auth_method_when_private_key_given(): void
    {
        $proxyClient = $this->createClientWithFakes([Http::response(['success' => true, 'output' => []], 200)]);

        $proxyClient->execute('h', 'u', '', [], 22, 'commands', 'PEM', 'pp');

        Http::assertSent(fn (Request $request): bool => $request['auth_method'] === 'private_key'
            && $request['private_key'] === 'PEM'
            && $request['passphrase'] === 'pp');
    }

    public function test_execute_returns_parsed_json_response(): void
    {
        $responseData = [
            'success' => true,
            'output' => [
                ['command' => 'ifconfig', 'output' => 'em0: flags=8843'],
                ['command' => 'netstat -rn', 'output' => 'Routing tables'],
            ],
        ];

        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode($responseData), 200),
        ]);

        $result = $proxyClient->execute(
            '10.0.0.1',
            'root',
            'secret',
            [
                ['command' => 'ifconfig'],
                ['command' => 'netstat -rn'],
            ],
        );

        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->output);
        $this->assertInstanceOf(CommandOutput::class, $result->output[0]);
        $this->assertInstanceOf(CommandOutput::class, $result->output[1]);
    }

    public function test_execute_throws_runtime_exception_on_409(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode(['error' => 'Host locked']), 409),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Host is currently locked by another request');

        $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password',
            [['command' => 'show version']],
        );
    }

    public function test_execute_throws_on_other_http_errors(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode(['error' => 'Internal server error']), 500),
        ]);

        $this->expectException(RequestException::class);

        $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password',
            [['command' => 'show version']],
        );
    }

    public function test_container_binding_resolves_correctly(): void
    {
        config([
            'aperture.ssh_proxy.host' => '127.0.0.1',
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
        ]);

        $resolved = $this->app->make(SshProxyClientInterface::class);

        $this->assertInstanceOf(SshProxyClient::class, $resolved);
    }

    public function test_connection_failure_throws_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7'));

        $this->expectException(ConnectionException::class);

        (new SshProxyClient('http://localhost:8022', 'test-key'))->execute('10.0.0.1', 'u', 'p', []);
    }

    public function test_execute_rethrows_non_409_client_exception(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode(['error' => 'Forbidden']), 403),
        ]);

        $this->expectException(RequestException::class);

        $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password',
            [['command' => 'show version']],
        );
    }

    public function test_invalid_configured_host_throws_actionable_laravel_exception(): void
    {
        config([
            'aperture.ssh_proxy.host' => 'http://bad host',
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
        ]);

        $this->app->forgetInstance(SshProxyClientInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid SSH proxy host configuration [aperture.ssh_proxy.host]. Use a plain hostname or IP without scheme/path.');

        /** @var SshProxyClientInterface $resolved */
        $resolved = $this->app->make(SshProxyClientInterface::class);
        $resolved->status();
    }

    #[DataProvider('invalidSshProxyHostsProvider')]
    public function test_invalid_configured_host_with_special_characters_throws_actionable_laravel_exception(string $invalidHost): void
    {
        config([
            'aperture.ssh_proxy.host' => $invalidHost,
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
        ]);

        $this->app->forgetInstance(SshProxyClientInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid SSH proxy host configuration [aperture.ssh_proxy.host]. Use a plain hostname or IP without scheme/path.');

        /** @var SshProxyClientInterface $resolved */
        $resolved = $this->app->make(SshProxyClientInterface::class);
        $resolved->status();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidSshProxyHostsProvider(): array
    {
        return [
            'contains userinfo separator' => ['switch-admin@10.0.0.5'],
            'contains query delimiter' => ['10.0.0.5?debug=1'],
            'contains fragment delimiter' => ['10.0.0.5#fragment'],
            'contains path segment' => ['10.0.0.5/path'],
            'contains scheme and userinfo style host' => ['http://user@10.0.0.5'],
        ];
    }

    public function test_execute_sends_key_auth_and_pinned_host_key_and_parses_observed_key(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode(['success' => true, 'output' => [], 'host_key' => 'ssh-ed25519 AAAA']), 200),
        ]);

        $result = $proxyClient->execute('h', 'u', '', [], 22, 'commands', 'PEM', 'pp', 'ssh-ed25519 PIN');

        Http::assertSent(fn (Request $request): bool => $request['private_key'] === 'PEM'
            && $request['passphrase'] === 'pp'
            && $request['host_key'] === 'ssh-ed25519 PIN');
        $this->assertSame('ssh-ed25519 AAAA', $result->hostKey);
        $this->assertNull($result->errorCode);
    }

    public function test_execute_defaults_key_fields_to_empty_strings(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode(['success' => true, 'output' => []]), 200),
        ]);

        $proxyClient->execute('h', 'u', 'p', []);

        Http::assertSent(fn (Request $request): bool => $request['private_key'] === ''
            && $request['passphrase'] === ''
            && $request['host_key'] === '');
    }

    public function test_execute_returns_result_for_host_key_mismatch_error_code(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode([
                'success' => false,
                'output' => [],
                'error' => 'SSH connection failed: host key mismatch',
                'error_code' => 'host_key_mismatch',
            ]), 500),
        ]);

        $result = $proxyClient->execute('h', 'u', 'p', [], 22, 'commands', null, null, 'ssh-ed25519 PIN');

        $this->assertFalse($result->success);
        $this->assertSame(CommandResult::HOST_KEY_MISMATCH, $result->errorCode);
        $this->assertStringContainsString('host key mismatch', $result->failureMessage());
    }

    public function test_execute_returns_result_for_invalid_private_key_error_code(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode([
                'success' => false,
                'output' => [],
                'error' => 'SSH connection failed: invalid private key: bad',
                'error_code' => 'invalid_private_key',
            ]), 400),
        ]);

        $result = $proxyClient->execute('h', 'u', '', [], 22, 'commands', 'garbage');

        $this->assertSame(CommandResult::INVALID_PRIVATE_KEY, $result->errorCode);
        $this->assertStringContainsString('private key', $result->failureMessage());
    }

    public function test_execute_still_throws_for_errors_without_error_code(): void
    {
        $proxyClient = $this->createClientWithFakes([
            Http::response(json_encode(['success' => false, 'error' => 'dial failed']), 500),
        ]);

        $this->expectException(RequestException::class);
        $proxyClient->execute('h', 'u', 'p', []);
    }
}
