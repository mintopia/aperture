<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

class PasskeyController extends Controller
{
    public function registerOptions(AttestationRequest $request): Responsable
    {
        return $request->toCreate();
    }

    public function register(AttestedRequest $request): JsonResponse
    {
        $request->save();

        /** @var User|null $user */
        $user = $request->user();

        if ($user) {
            AuditLog::record(
                action: 'user.passkey_registered',
                subject: $user,
                process: 'account',
                metadata: ['ip' => $request->getClientIp()],
            );
        }

        return response()->json(['success' => true]);
    }

    public function loginOptions(AssertionRequest $request): Responsable
    {
        // CRITICAL: Must pass email to scope credentials to the user
        return $request->toVerify($request->only('email'));
    }

    public function login(AssertedRequest $request): JsonResponse
    {
        $user = $request->login();

        if ($user instanceof WebAuthnAuthenticatable) {
            $request->session()->regenerate();

            return response()->json(['success' => true, 'redirect' => '/']);
        }

        return response()->json(['success' => false, 'message' => 'Authentication failed.'], 422);
    }

    public function destroy(Request $request, string $credentialId): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false], 403);
        }

        $deleted = $user->webAuthnCredentials()->where('id', $credentialId)->delete();

        if ($deleted > 0) {
            /** @var User $user */
            AuditLog::record(
                action: 'user.passkey_deleted',
                subject: $user,
                process: 'account',
                metadata: ['ip' => $request->getClientIp()],
            );
        }

        return response()->json(['success' => $deleted > 0]);
    }
}
