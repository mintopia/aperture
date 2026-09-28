<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\SwitchHostKeyMismatchException;
use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Models\SwitchConfig;
use App\Services\Integration\IntegrationConfigMerger;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\NetworkSwitch\SwitchConnectionTester;
use App\Services\ValueObjects\TestConnectionResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestConnectionController extends Controller
{
    public function __construct(
        private IntegrationTesterRegistry $registry,
        private IntegrationConfigMerger $configMerger,
    ) {}

    public function test(Request $request, string $service): JsonResponse
    {
        if (! $this->registry->has($service)) {
            return response()->json(['success' => false, 'message' => 'Unknown service: '.$service], 404);
        }

        $config = $this->configMerger->merge($service, $request);
        $result = $this->registry->get($service)->connect($config);

        $this->logResult($service, $result);

        return $this->jsonResult($result);
    }

    public function testSwitch(SwitchConfig $switchConfig, SwitchConnectionTester $tester): JsonResponse
    {
        $requestMethod = 'SSH';
        $requestUrl = $switchConfig->hostname;
        $result = $tester->test($switchConfig);

        if ($result->success) {
            $summary = sprintf('Found %d ports in %ss.', $result->portCount, $result->duration);

            ConnectionTestLog::record(
                'switch-'.$switchConfig->hostname,
                true,
                'Connected successfully',
                null,
                $summary,
                $requestMethod,
                $requestUrl,
            );

            return response()->json([
                'success' => true,
                'message' => 'Connected successfully',
                'request_method' => $requestMethod,
                'request_url' => $requestUrl,
                'output' => $summary,
            ]);
        }

        $throwable = $result->exception;
        $statusCode = null;

        Log::error('Switch connection test failed', [
            'switch_id' => $switchConfig->id,
            'hostname' => $switchConfig->hostname,
            'error' => $throwable?->getMessage(),
        ]);

        if ($throwable instanceof ConnectException) {
            $message = 'Could not reach SSH proxy: '.$throwable->getMessage();
        } elseif ($throwable instanceof RequestException) {
            $statusCode = $throwable->getResponse()?->getStatusCode();
            $message = 'SSH proxy error: '.$throwable->getMessage();
        } elseif ($throwable instanceof SwitchHostKeyMismatchException) {
            $message = $throwable->getMessage();
        } else {
            $message = 'Connection failed: '.$throwable?->getMessage();
        }

        return $this->recordAndReturnError('switch', $switchConfig->hostname, $message, $statusCode, $requestMethod, $requestUrl);
    }

    /**
     * Record a failed connection test log entry and return a JSON error response.
     */
    private function recordAndReturnError(
        string $service,
        string $hostname,
        string $message,
        ?int $statusCode = null,
        ?string $requestMethod = null,
        ?string $requestUrl = null,
    ): JsonResponse {
        ConnectionTestLog::record($service.'-'.$hostname, false, $message, null, null, $requestMethod, $requestUrl);

        return response()->json([
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
            'request_method' => $requestMethod,
            'request_url' => $requestUrl,
        ]);
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
