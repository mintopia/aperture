<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IntegrationConfig;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(Str::random(24));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $script = ["'self'", sprintf("'nonce-%s'", $nonce)];
        $connect = ["'self'"];
        $style = ["'self'", sprintf("'nonce-%s'", $nonce)];
        $font = ["'self'", 'data:'];
        $img = ["'self'", 'data:', 'blob:'];

        $reverbHost = config('reverb.frontend.host');
        if (is_string($reverbHost) && $reverbHost !== '') {
            $scheme = config('reverb.frontend.scheme') === 'http' ? 'ws' : 'wss';
            $port = (int) config('reverb.frontend.port');
            $connect[] = sprintf('%s://%s', $scheme, $reverbHost).($port > 0 ? ':'.$port : '');
        }

        array_push($connect, ...$this->detectionOrigins());

        $hot = public_path('hot');
        if (is_file($hot)) {
            $origin = rtrim(trim((string) file_get_contents($hot)), '/');
            $parts = parse_url($origin);
            if (isset($parts['scheme'], $parts['host'])) {
                $hostPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                $ws = ($parts['scheme'] === 'https' ? 'wss' : 'ws').'://'.$hostPort;
                $script[] = $origin;
                $script[] = "'unsafe-eval'";
                $style[] = $origin;
                $font[] = $origin;
                $img[] = $origin;
                $connect[] = $origin;
                $connect[] = $ws;
            }
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'img-src '.implode(' ', $img),
            'font-src '.implode(' ', $font),
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * @return list<string>
     */
    private function detectionOrigins(): array
    {
        try {
            $urls = [
                Setting::get('dns.check_url', ''),
                IntegrationConfig::getValue('ipv6', 'detection_endpoint', ''),
            ];
        } catch (Throwable) {
            return [];
        }

        $origins = [];
        foreach ($urls as $url) {
            $origin = is_string($url) ? $this->originOf($url) : null;
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    private function originOf(string $url): ?string
    {
        $placeholder = 'uuid-placeholder';
        $parts = parse_url(str_replace('{uuid}', $placeholder, $url));
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        $labels = explode('.', $host);
        foreach ($labels as $i => $label) {
            if (! str_contains($label, $placeholder)) {
                continue;
            }

            if ($i !== 0 || $label !== $placeholder) {
                return null;
            }

            $labels[$i] = '*';
        }

        return $scheme.'://'.implode('.', $labels).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
