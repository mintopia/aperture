<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if ($user) {
            $request->session()->regenerate();

            return response()->json(['success' => true, 'redirect' => '/']);
        }

        return response()->json(['success' => false, 'message' => 'Authentication failed.'], 422);
    }

    public function destroy(Request $request, string $credentialId): JsonResponse
    {
        $deleted = $request->user()->webAuthnCredentials()->where('id', $credentialId)->delete();

        return response()->json(['success' => $deleted > 0]);
    }
}
