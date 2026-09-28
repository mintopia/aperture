<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Kea\KeaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class KeaClientTest extends TestCase
{
    public function test_send_command_includes_service_and_omits_empty_arguments(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'text' => 'ok'],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');
        $client->sendCommand('list-commands');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'list-commands'
                && $data['service'] === ['dhcp4']
                && ! array_key_exists('arguments', $data);
        });
    }

    public function test_send_command_includes_arguments_when_provided(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'arguments' => []],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');
        $client->sendCommand('lease4-get-page', ['from' => '0.0.0.0', 'limit' => 10]);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['arguments'] === ['from' => '0.0.0.0', 'limit' => 10];
        });
    }

    public function test_applies_basic_auth_when_both_username_and_password_present(): void
    {
        Http::fake(['kea.local' => Http::response([['result' => 0]])]);

        $client = new KeaClient(endpoint: 'https://kea.local', username: 'admin', password: 'secret');
        $client->sendCommand('list-commands');

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth) && str_starts_with($auth[0], 'Basic ');
        });
    }

    public function test_omits_basic_auth_when_username_missing(): void
    {
        Http::fake(['kea.local' => Http::response([['result' => 0]])]);

        $client = new KeaClient(endpoint: 'https://kea.local', username: null, password: 'secret');
        $client->sendCommand('list-commands');

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return empty($auth);
        });
    }

    public function test_omits_basic_auth_when_password_missing(): void
    {
        Http::fake(['kea.local' => Http::response([['result' => 0]])]);

        $client = new KeaClient(endpoint: 'https://kea.local', username: 'admin');
        $client->sendCommand('list-commands');

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return empty($auth);
        });
    }

    public function test_omits_basic_auth_when_both_missing(): void
    {
        Http::fake(['kea.local' => Http::response([['result' => 0]])]);

        $client = new KeaClient(endpoint: 'https://kea.local');
        $client->sendCommand('list-commands');

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return empty($auth);
        });
    }

    public function test_result_code_zero_returns_entry_with_arguments(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'text' => 'ok', 'arguments' => ['foo' => 'bar']],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');
        $entry = $client->sendCommand('config-get');

        $this->assertSame(0, $entry['result']);
        $this->assertSame(['foo' => 'bar'], $entry['arguments']);
    }

    public function test_result_code_three_is_treated_as_empty_not_an_error(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');
        $entry = $client->sendCommand('lease4-get-page');

        $this->assertSame(3, $entry['result']);
        $this->assertArrayNotHasKey('arguments', $entry);
    }

    public function test_other_result_code_throws_with_kea_text_message(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 1, 'text' => 'command not supported'],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('command not supported');

        $client->sendCommand('bogus-command');
    }

    public function test_http_failure_propagates_as_request_exception(): void
    {
        Http::fake(['kea.local' => Http::response('Unauthorized', 401)]);

        $client = new KeaClient(endpoint: 'https://kea.local');

        $this->expectException(RequestException::class);

        $client->sendCommand('list-commands');
    }

    public function test_connection_failure_propagates_as_connection_exception(): void
    {
        Http::fake(['kea.local' => fn () => throw new ConnectionException('Connection refused')]);

        $client = new KeaClient(endpoint: 'https://kea.local');

        $this->expectException(ConnectionException::class);

        $client->sendCommand('list-commands');
    }

    public function test_unexpected_response_shape_throws_runtime_exception(): void
    {
        Http::fake(['kea.local' => Http::response(['not' => 'a list'])]);

        $client = new KeaClient(endpoint: 'https://kea.local');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected response shape');

        $client->sendCommand('list-commands');
    }

    public function test_list_commands_extracts_flat_list_of_command_names(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'arguments' => ['build-report', 'config-get', 'lease4-get-page']],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');
        $commands = $client->listCommands();

        $this->assertSame(['build-report', 'config-get', 'lease4-get-page'], $commands);
    }

    public function test_list_commands_returns_empty_array_when_arguments_missing(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');

        $this->assertSame([], $client->listCommands());
    }

    public function test_list_commands_returns_empty_array_when_arguments_not_an_array(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'arguments' => 'not-an-array'],
            ]),
        ]);

        $client = new KeaClient(endpoint: 'https://kea.local');

        $this->assertSame([], $client->listCommands());
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['kea.local' => Http::response([['result' => 0]])]);

        $client = new KeaClient(endpoint: 'https://kea.local/');
        $client->sendCommand('list-commands');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://kea.local');
    }
}
