<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectionTestLog;
use App\Services\Integration\IntegrationConfigMerger;
use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestConnectionController extends Controller
{
    public function __construct(
        private IntegrationConfigMerger $configMerger,
    ) {}

    public function test(Request $request, string $service): JsonResponse
    {
        $testerClass = config('integrations.'.$service.'.tester');

        if (! is_string($testerClass)) {
            return response()->json(['success' => false, 'message' => 'Unknown service: '.$service], 404);
        }

        $config = $this->configMerger->merge($service, $request);
        $tester = resolve($testerClass);
        assert($tester instanceof TestableIntegration);
        $result = $tester->connect($config);

        $this->logResult($service, $result);

        return $this->jsonResult($result);
    }

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
