<?php

declare(strict_types=1);

namespace App\Services\Borealis;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\BorealisService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

class DeviceCode
{
    public string $uri;

    public string $fullUri;

    public string $userCode;

    protected string $deviceCode;

    public int $interval;

    public CarbonImmutable $expiresAt;

    public ?string $nickname = null;

    public ?string $email = null;

    public ?string $avatarUrl = null;

    public ?string $accessToken = null;

    public ?string $refreshToken = null;

    public ?string $externalId = null;

    public ?CarbonImmutable $accessTokenExpiresAt = null;

    public DeviceCodeStatus $status = DeviceCodeStatus::dcsPending;

    public function __construct(protected BorealisService $service, protected string $provider) {}

    /**
     * @return array<string>
     */
    public function __sleep(): array
    {
        $vars = get_object_vars($this);
        unset($vars['service']);

        return array_keys($vars);
    }

    public function __wakeup()
    {
        $this->service = resolve(BorealisService::class);
    }

    public function hasFailed(): bool
    {
        if ($this->externalId) {
            return false;
        }

        return $this->status === DeviceCodeStatus::dcsPending && $this->expiresAt->isBefore(CarbonImmutable::now());
    }

    public function parse(stdClass $response): self
    {
        $this->deviceCode = $response->device_code;
        $this->userCode = $response->user_code;
        $this->interval = $response->interval;
        $this->uri = $response->verification_uri;
        $this->fullUri = $response->verification_uri_complete;
        $this->expiresAt = CarbonImmutable::now()->addSeconds($response->expires_in);

        return $this;
    }

    public function check(): DeviceCodeStatus
    {
        if ($this->status !== DeviceCodeStatus::dcsPending) {
            return $this->status;
        }

        try {
            $response = $this->service->check($this->deviceCode);
        } catch (RequestException $requestException) {
            if ($requestException->getMessage() !== 'authorization_pending') {
                $this->status = DeviceCodeStatus::dcsFailed;
            }

            return $this->status;
        }

        $this->externalId = $response->user->id;
        $this->nickname = $response->user->nickname;
        $this->email = $response->user->email;
        $this->avatarUrl = $response->user->avatar_url;
        $this->accessToken = $response->access_token;
        $this->refreshToken = $response->refresh_token;
        $this->accessTokenExpiresAt = CarbonImmutable::now()->addSeconds($response->expires_in);
        $this->status = DeviceCodeStatus::dcsSuccessful;

        return $this->status;
    }

    protected function getAuthProvider(): ?AuthProvider
    {
        return AuthProvider::whereCode($this->provider)->first();
    }

    public function getUser(): ?User
    {
        if ($this->status !== DeviceCodeStatus::dcsSuccessful) {
            return null;
        }

        $authProvider = $this->getAuthProvider();
        if (! $authProvider instanceof AuthProvider) {
            return null;
        }

        $auth = UserAuthentication::whereAuthProviderId($authProvider->id)
            ->whereExternalId($this->externalId)
            ->first();
        if (! $auth) {
            $auth = new UserAuthentication;
            $auth->provider()->associate($authProvider);
            $user = User::whereEmail($this->email)->first();
            if (! $user) {
                $user = new User;
                $user->email = (string) $this->email;
            }

            $auth->user()->associate($user);
        }

        $auth->external_id = (string) $this->externalId;
        $auth->access_token = (string) $this->accessToken;
        $auth->refresh_token = (string) $this->refreshToken;
        $auth->token_expires_at = $this->accessTokenExpiresAt?->toDateTimeString() ?? '';
        $auth->user->nickname = (string) $this->nickname;
        DB::transaction(function () use ($auth): void {
            $auth->user->save();
            if (! $auth->exists || $auth->user_id === 0) {
                $auth->user_id = $auth->user->id;
            }

            $auth->save();
        });

        return $auth->user;
    }
}
