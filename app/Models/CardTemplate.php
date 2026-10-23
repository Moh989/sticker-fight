<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardTemplate extends Model
{
    protected $table = 'card_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }
}
