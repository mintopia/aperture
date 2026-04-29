<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e): void {
            //
        });
    }

    public function render($request, Throwable $e): Response
    {
        /** @var Response $response */
        $response = parent::render($request, $e);

        $status = $response->getStatusCode();

        if (! $this->shouldRenderInertiaErrorPage($request, $status)) {
            return $response;
        }

        return Inertia::render('Error', ['status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    private function shouldRenderInertiaErrorPage(Request $request, int $status): bool
    {
        if ($request->expectsJson() && ! $request->inertia()) {
            return false;
        }

        return in_array($status, [403, 404, 419, 429, 500, 503], true);
    }
}
