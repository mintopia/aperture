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
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(Str::random(24));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
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
        $style = ["'self'", sprintf("'nonce-%s'", $nonce)];

        $hot = Vite::hotFile();
        if (is_file($hot)) {
            $origin = rtrim(trim((string) file_get_contents($hot)), '/');
            if (parse_url($origin, PHP_URL_HOST) !== null) {
                $script[] = $origin;
                $script[] = "'unsafe-eval'";
                $style[] = $origin;
            }
        }

        // Detection endpoints, Reverb and admin-configured embeds are arbitrary hosts, often plain HTTP on the event LAN.
        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            "img-src 'self' data: blob: https: http:",
            "media-src 'self' data: blob: https: http:",
            "font-src 'self' data: https: http:",
            "connect-src 'self' https: http: wss: ws:",
            "frame-src 'self' https: http:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
