<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Hash;

trait ConfirmsPassword
{
    protected function confirmPassword(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        /** @var User $user */
        $user = $this->user();

        if ($user->password === null) {
            $validator->errors()->add('password', 'Password required for destructive operations.');

            return;
        }

        if (! Hash::check($this->string('password')->value(), $user->password)) {
            $validator->errors()->add('password', 'The provided password is incorrect.');
        }
    }
}
