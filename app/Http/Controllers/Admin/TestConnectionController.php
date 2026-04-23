<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Models\SwitchConfig;
use App\Services\Integration\IntegrationConfigMerger;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\SshProxy\CommandOutput;
use App\Services\ValueObjects\TestConnectionResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            return response()->json(['success' => false, 'message' => 'Unknown service: '.$service], 404);
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

            $outputData = json_encode($result->output) ?: null;

            if (! $result->success) {
                $outputSummary = collect($result->output)
                    ->map(fn (CommandOutput $o): string => $o->output)
                    ->implode("\n");

                Log::warning('Switch connection test failed', [
                    'switch_id' => $switchConfig->id,
                    'hostname' => $switchConfig->hostname,
                    'error' => $result->error,
                    'output' => $outputSummary,
                ]);

                $message = $result->error ?? 'Unknown error';

                ConnectionTestLog::record(
                    'switch-'.$switchConfig->hostname,
                    false,
                    $message,
                    null,
                    $outputData,
                    $requestMethod,
                    $requestUrl,
                );

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'request_method' => $requestMethod,
                    'request_url' => $requestUrl,
                    'output' => $result->output,
                    'details' => [
                        'error' => $result->error,
                        'output' => mb_substr($outputSummary, 0, 500),
                        'hostname' => $switchConfig->hostname,
                    ],
                ]);
            }

            ConnectionTestLog::record(
                'switch-'.$switchConfig->hostname,
                true,
                'Connected successfully',
                null,
                $outputData,
                $requestMethod,
                $requestUrl,
            );

            return response()->json([
                'success' => true,
                'message' => 'Connected successfully',
                'request_method' => $requestMethod,
                'request_url' => $requestUrl,
                'output' => $result->output,
            ]);
        } catch (ConnectException $connectException) {
            Log::error('SSH proxy unreachable during switch test', [
                'switch_id' => $switchConfig->id,
                'hostname' => $switchConfig->hostname,
                'error' => $connectException->getMessage(),
            ]);

            return $this->recordAndReturnError(
                'switch',
                $switchConfig->hostname,
                'Could not reach SSH proxy: '.$connectException->getMessage(),
                null,
                $requestMethod,
                $requestUrl,
            );
        } catch (RequestException $requestException) {
            $response = $requestException->getResponse();
            $statusCode = $response?->getStatusCode();

            Log::error('SSH proxy request failed during switch test', [
                'switch_id' => $switchConfig->id,
                'hostname' => $switchConfig->hostname,
                'error' => $requestException->getMessage(),
                'status' => $statusCode,
            ]);

            return $this->recordAndReturnError(
                'switch',
                $switchConfig->hostname,
                'SSH proxy error: '.$requestException->getMessage(),
                $statusCode,
                $requestMethod,
                $requestUrl,
            );
        } catch (Throwable $throwable) {
            Log::error('Switch connection test exception', [
                'switch_id' => $switchConfig->id,
                'hostname' => $switchConfig->hostname,
                'error' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return $this->recordAndReturnError(
                'switch',
                $switchConfig->hostname,
                'Connection failed: '.$throwable->getMessage(),
                null,
                $requestMethod,
                $requestUrl,
            );
        }
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
