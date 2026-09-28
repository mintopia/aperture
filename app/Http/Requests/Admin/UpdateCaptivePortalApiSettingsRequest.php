<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCaptivePortalApiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_portal_url' => 'nullable|url|max:500',
            'venue_info_url' => 'nullable|url|max:500',
            'can_extend_session' => 'required|boolean',
        ];
    }
}
