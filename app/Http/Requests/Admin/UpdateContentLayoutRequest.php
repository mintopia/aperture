<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContentLayoutRequest extends FormRequest
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
            'blocks' => 'required|array',
            'blocks.*.id' => 'required|exists:content_blocks,id',
            'blocks.*.grid_col' => 'required|integer|min:1|max:3',
            'blocks.*.grid_row' => 'required|integer|min:1',
            'blocks.*.col_span' => 'required|integer|min:1|max:3',
            'blocks.*.row_span' => 'required|integer|min:1',
        ];
    }
}
