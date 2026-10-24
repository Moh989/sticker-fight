<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardTemplate extends Model
{
    public const CHIP_TYPES = ['small_chip' => 'قالب مع شريحة صغيرة', 'no_chip' => 'قالب بدون شريحة'];

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

    public function isStorefrontChoice(): bool
    {
        return $this->side === 'back' || array_key_exists($this->kind ?? '', self::CHIP_TYPES);
    }

    public function storefrontName(): string
    {
        return __(self::CHIP_TYPES[$this->kind ?? ''] ?? $this->name);
    }
}
