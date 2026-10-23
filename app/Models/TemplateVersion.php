<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(function ($version) {
            if ($version->getRawOriginal('approved_at') !== null) {
                throw new \LogicException('Approved templates require a new version.');
            }
        });
    }

    protected $table = 'template_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['geometry' => 'array', 'compatibility' => 'array', 'approved_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CardTemplate::class, 'card_template_id');
    }
}
