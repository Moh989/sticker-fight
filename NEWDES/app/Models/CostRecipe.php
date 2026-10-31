<?php

namespace App\Models;

use Database\Factories\CostRecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostRecipe extends Model
{
    /** @use HasFactory<CostRecipeFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }
}
