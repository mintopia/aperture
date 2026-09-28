<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

class UpdateCoverImageRequest extends FormRequest
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
            'cover_image' => [
                'required',
                File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(5120),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $size = @getimagesize($value->getRealPath());
                    if ($size === false) {
                        $fail('The cover image must be a valid image.');

                        return;
                    }

                    [$width] = $size;
                    if ($width < 600) {
                        $fail('The cover image must be at least 600 pixels wide.');
                    }
                },
            ],
        ];
    }
}
