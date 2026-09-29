<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'slug', 'content'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;
}
