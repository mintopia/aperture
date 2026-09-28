<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

class UpdateLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $size = @getimagesize($value->getRealPath());
                    if ($size === false) {
                        $fail('The logo must be a valid image.');

                        return;
                    }

                    [$width, $height] = $size;
                    if ($width !== $height) {
                        $fail('The logo must be square (1:1 aspect ratio).');
                    }

                    if ($width < 64 || $height < 64) {
                        $fail('The logo must be at least 64x64 pixels.');
                    }
                },
            ],
        ];
    }
}
