<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\BorealisService;
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

            ConnectionTestLog::record('opnsense', true, 'Connected successfully');

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
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

            ConnectionTestLog::record('librenms', true, 'Connected successfully');

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
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

            ConnectionTestLog::record('ntopng', true, 'Connected successfully');

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
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
            $response = Http::withOptions([
                'verify' => (bool) ($config['verify_ssl'] ?? true),
            ])
                ->timeout(10)
                ->get($endpoint.'/api/info/client');

            $response->throw();

            ConnectionTestLog::record('pihole', true, 'Connected successfully');

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('pihole', false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testBorealis(BorealisService $borealis): JsonResponse
    {
        try {
            $borealis->getDeviceCodeRaw('test');
            ConnectionTestLog::record('borealis', true, 'Borealis OAuth2 endpoint is reachable.');

            return response()->json(['success' => true, 'message' => 'Borealis OAuth2 endpoint is reachable.']);
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

            ConnectionTestLog::record(
                'switch-'.$switchConfig->hostname,
                $result->success,
                $result->success ? 'Connected successfully' : ($result->error ?? 'Unknown error'),
            );

            return response()->json([
                'success' => $result->success,
                'message' => $result->success ? 'Connected successfully' : ($result->error ?? 'Unknown error'),
            ]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record('switch-'.$switchConfig->hostname, false, 'Connection failed: '.$throwable->getMessage());

            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }
}
