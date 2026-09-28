<?php

declare(strict_types=1);

namespace App\Services\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class ExternalHttp
{
    public static function request(string $baseUrl = '', bool $verifySsl = true): PendingRequest
    {
        $request = Http::withOptions(['verify' => $verifySsl])
            ->timeout((int) config('services.external_http.timeout', 10))
            ->connectTimeout((int) config('services.external_http.connect_timeout', 5));

        return $baseUrl === '' ? $request : $request->baseUrl($baseUrl);
    }
}
