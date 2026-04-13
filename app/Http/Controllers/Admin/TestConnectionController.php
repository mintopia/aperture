<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\SshProxy\SshProxyClientInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class TestConnectionController extends Controller
{
    public function testOpnsense(): JsonResponse
    {
        try {
            $config = IntegrationConfig::getAll('opnsense');
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::withOptions([
                'verify' => (bool) ($config['verify_ssl'] ?? true),
            ])
                ->withBasicAuth($config['key'] ?? '', $config['secret'] ?? '')
                ->timeout(10)
                ->get($endpoint.'/api/captiveportal/service/reconfigure');

            $response->throw();

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
        } catch (Throwable $throwable) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testLibrenms(): JsonResponse
    {
        try {
            $config = IntegrationConfig::getAll('librenms');
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::withHeaders(['X-Auth-Token' => $config['api_key'] ?? ''])
                ->timeout(10)
                ->get($endpoint.'/api/v0');

            $response->throw();

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
        } catch (Throwable $throwable) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testNtopng(): JsonResponse
    {
        try {
            $config = IntegrationConfig::getAll('ntopng');
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::timeout(10)
                ->get($endpoint.'/lua/rest/v2/get/ntopng/interfaces.lua');

            $response->throw();

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
        } catch (Throwable $throwable) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }

    public function testPihole(): JsonResponse
    {
        try {
            $config = IntegrationConfig::getAll('pihole');
            $endpoint = rtrim($config['endpoint'] ?? '', '/');
            $response = Http::withOptions([
                'verify' => (bool) ($config['verify_ssl'] ?? true),
            ])
                ->timeout(10)
                ->get($endpoint.'/api/dns/status');

            $response->throw();

            return response()->json(['success' => true, 'message' => 'Connected successfully']);
        } catch (Throwable $throwable) {
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

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Connected successfully' : ($result['error'] ?? 'Unknown error'),
            ]);
        } catch (Throwable $throwable) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$throwable->getMessage()]);
        }
    }
}
