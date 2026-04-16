<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\BorealisService;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\LibreNmsService;
use App\Services\NtopNgService;
use App\Services\PiHole\PiHoleApiService;
use App\Services\SshProxy\SshProxyClientInterface;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TestConnectionController extends Controller
{
    public function testOpnsense(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('opnsense', $request);
        $result = OpnSenseApiService::testConnection($config);

        $this->logResult('opnsense', $result);

        return $this->jsonResult($result);
    }

    public function testLibrenms(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('librenms', $request);
        $result = LibreNmsService::testConnection($config);

        $this->logResult('librenms', $result);

        return $this->jsonResult($result);
    }

    public function testNtopng(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('ntopng', $request);
        $result = NtopNgService::testConnection($config);

        $this->logResult('ntopng', $result);

        return $this->jsonResult($result);
    }

    public function testPihole(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('pihole', $request);
        $result = PiHoleApiService::testConnection($config);

        $this->logResult('pihole', $result);

        return $this->jsonResult($result);
    }

    public function testBorealis(Request $request): JsonResponse
    {
        $config = $this->mergeConfig('borealis', $request);
        $result = BorealisService::testConnection($config);

        $this->logResult('borealis', $result);

        return $this->jsonResult($result);
    }

    public function testSwitch(SwitchConfig $switchConfig, SshProxyClientInterface $proxyClient): JsonResponse
    {
        $requestMethod = 'SSH';
        $requestUrl = $switchConfig->hostname;
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
                $requestMethod,
                $requestUrl,
                null,
            );

            return response()->json([
                'success' => $result->success,
                'message' => $message,
                'request_method' => $requestMethod,
                'request_url' => $requestUrl,
                'output' => $result->output,
            ]);
        } catch (Throwable $throwable) {
            ConnectionTestLog::record(
                'switch-'.$switchConfig->hostname, false, 'Connection failed: '.$throwable->getMessage(),
                null, null, $requestMethod, $requestUrl, null
            );

            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$throwable->getMessage(),
                'request_method' => $requestMethod,
                'request_url' => $requestUrl,
            ]);
        }
    }

    /**
     * Merge saved DB config with non-empty request values (request takes precedence).
     *
     * @return array<string, mixed>
     */
    private function mergeConfig(string $integration, Request $request): array
    {
        $dbConfig = IntegrationConfig::getAll($integration);

        return array_merge($dbConfig, array_filter($request->all(), fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Write a TestConnectionResult to the connection test log.
     */
    private function logResult(string $integration, TestConnectionResult $result): void
    {
        ConnectionTestLog::record(
            $integration,
            $result->success,
            $result->message,
            null,
            $result->responseBody,
            $result->requestMethod,
            $result->requestUrl,
            $result->responseStatus,
        );
    }

    /**
     * Build the standard JSON response from a TestConnectionResult.
     */
    private function jsonResult(TestConnectionResult $result): JsonResponse
    {
        $data = [
            'success' => $result->success,
            'message' => $result->message,
            'request_method' => $result->requestMethod,
            'request_url' => $result->requestUrl,
        ];

        if ($result->success) {
            $data['response_status'] = $result->responseStatus;
            $data['output'] = $result->output;
        }

        return response()->json($data);
    }
}
