<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Page;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        /** @var Page|null $page */
        $page = $this->route('page');
        $pageId = $page instanceof Page ? (string) $page->id : 'NULL';

        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:pages,slug,'.$pageId,
            'content' => 'nullable|string|max:65535',
        ];
    }
}
