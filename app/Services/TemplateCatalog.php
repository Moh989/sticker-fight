<?php

namespace App\Services;

use App\Models\CardTemplate;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TemplateCatalog
{
    /** @return Collection<int, TemplateVersion> */
    public static function versions(): Collection
    {
        return TemplateVersion::with('template')
            ->whereHas('template', fn (Builder $query): Builder => $query->where('active', true)
                ->where(fn (Builder $choices): Builder => $choices->where('side', 'back')->orWhereIn('kind', array_keys(CardTemplate::CHIP_TYPES))))
            ->orderByRaw('approved_at IS NULL')->orderByDesc('version')->orderByDesc('id')->get()
            ->unique(fn (TemplateVersion $version): string => $version->template->side === 'front' ? 'front:'.$version->template->kind : 'back:'.$version->card_template_id)
            ->sortBy(fn (TemplateVersion $version): int => match ($version->template->kind) {
                'small_chip' => 0, 'no_chip' => 1, default => 2
            })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function choices(): Collection
    {
        return self::versions()->map(fn (TemplateVersion $version): array => self::describe($version));
    }

    /** @return array<string, mixed> */
    public static function describe(TemplateVersion $version): array
    {
        return [
            'id' => $version->id, 'name' => $version->template->storefrontName(), 'kind' => $version->template->kind,
            'side' => $version->template->side, 'approved' => (bool) $version->approved_at, 'version' => $version->version,
            'geometry' => $version->geometry, 'compatibility' => $version->compatibility,
        ];
    }
}
