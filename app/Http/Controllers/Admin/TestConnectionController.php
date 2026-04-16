<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\SshProxy\SshProxyClientInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class TestConnectionController extends Controller
{
    public function testOpnsense(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('opnsense');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::withOptions([
                'verify' => (bool) ($config['verify_ssl'] ?? true),
            ])
                ->withBasicAuth($config['key'] ?? '', $config['secret'] ?? '')
                ->timeout(10)
                ->get($endpoint.'/api/captiveportal/service/reconfigure');

            $response->throw();

            $body = $response->body();
            ConnectionTestLog::record('opnsense', true, 'Connected successfully', null, $body);

            return response()->json(['success' => true, 'message' => 'Connected successfully', 'output' => $response->json() ?? $body]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('opnsense', false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testLibrenms(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('librenms');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::withHeaders(['X-Auth-Token' => $config['api_key'] ?? ''])
                ->timeout(10)
                ->get($endpoint.'/api/v0');

            $response->throw();

            $body = $response->body();
            ConnectionTestLog::record('librenms', true, 'Connected successfully', null, $body);

            return response()->json(['success' => true, 'message' => 'Connected successfully', 'output' => $response->json() ?? $body]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('librenms', false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testNtopng(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('ntopng');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::timeout(10)
                ->get($endpoint.'/lua/rest/v2/get/ntopng/interfaces.lua');

            $response->throw();

            $body = $response->body();
            ConnectionTestLog::record('ntopng', true, 'Connected successfully', null, $body);

            return response()->json(['success' => true, 'message' => 'Connected successfully', 'output' => $response->json() ?? $body]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('ntopng', false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testPihole(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('pihole');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $password = $config['password'] ?? '';

            $response = Http::withOptions([
                'verify' => (bool) ($config['verify_ssl'] ?? true),
            ])
                ->asJson()
                ->timeout(10)
                ->post($endpoint.'/api/auth', ['password' => $password]);

            $response->throw();

            $body = $response->body();
            ConnectionTestLog::record('pihole', true, 'Connected and authenticated successfully', null, $body);

            return response()->json(['success' => true, 'message' => 'Connected and authenticated successfully', 'output' => $response->json() ?? $body]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('pihole', false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testBorealis(Request $request): JsonResponse
    {
        try {
            $dbConfig = IntegrationConfig::getAll('borealis');
            $config = array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));

            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $clientId = $config['client_id'] ?? '';
            $clientSecret = $config['client_secret'] ?? '';

            $response = Http::asForm()
                ->timeout(10)
                ->withBasicAuth($clientId, $clientSecret)
                ->post($endpoint.'/oauth2/device', [
                    'scope' => 'test',
                ]);

            $response->throw();

            $body = $response->body();
            $responseBody = $response->json();
            ConnectionTestLog::record('borealis', true, 'Authenticated and received device code.', null, $body);

            return response()->json(['success' => true, 'message' => 'Authenticated and received device code.', 'output' => $responseBody ?? $body]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('borealis', false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testSwitch(SwitchConfig $switchConfig, SshProxyClientInterface $proxyClient): JsonResponse
    {
        try {
            $result = $proxyClient->execute(
                $switchConfig->hostname,
                $switchConfig->username,
                $switchConfig->password,
                [['command' => '', 'expect' => '/^.*[>#]$/']],
            );

            $message = $result->success ? 'Connected successfully' : ($result->error ?? 'Unknown error');
            $outputData = json_encode($result->output) ?: null;

            ConnectionTestLog::record(
                'switch-'.$switchConfig->hostname,
                $result->success,
                $message,
                null,
                $outputData,
            );

            return response()->json([
                'success' => $result->success,
                'message' => $message,
                'output' => $result->output,
            ]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('switch-'.$switchConfig->hostname, false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }
}
