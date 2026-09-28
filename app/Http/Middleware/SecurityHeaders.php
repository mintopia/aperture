<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
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
        $script = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];
        $style = ["'self'", "'unsafe-inline'"];
        $font = ["'self'", 'data:'];
        $img = ["'self'", 'data:', 'blob:'];

        $reverbHost = config('reverb.frontend.host');
        if (is_string($reverbHost) && $reverbHost !== '') {
            $scheme = config('reverb.frontend.scheme') === 'http' ? 'ws' : 'wss';
            $port = (int) config('reverb.frontend.port');
            $connect[] = "{$scheme}://{$reverbHost}".($port > 0 ? ":{$port}" : '');
        }

        $hot = public_path('hot');
        if (is_file($hot)) {
            $origin = rtrim(trim((string) file_get_contents($hot)), '/');
            $parts = parse_url($origin);
            if (isset($parts['scheme'], $parts['host'])) {
                $hostPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                $ws = ($parts['scheme'] === 'https' ? 'wss' : 'ws').'://'.$hostPort;
                array_push($script, $origin, "'unsafe-eval'");
                array_push($style, $origin);
                array_push($font, $origin);
                array_push($img, $origin);
                array_push($connect, $origin, $ws);
            }
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            // Admin custom CSS, Vue :style bindings and captive-portal theme blocks are inline.
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
}
