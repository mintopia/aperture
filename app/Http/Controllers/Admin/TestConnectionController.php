<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Models\SwitchConfig;
use App\Services\Integration\IntegrationConfigMerger;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\SshProxy\SshProxyClientInterface;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TestConnectionController extends Controller
{
    public function __construct(
        private IntegrationTesterRegistry $registry,
        private IntegrationConfigMerger $configMerger,
    ) {}

    public function test(Request $request, string $service): JsonResponse
    {
        if (! $this->registry->has($service)) {
            return response()->json(['success' => false, 'message' => "Unknown service: {$service}"], 404);
        }

        $config = $this->configMerger->merge($service, $request);
        $result = $this->registry->get($service)->connect($config);

        $this->logResult($service, $result);

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
