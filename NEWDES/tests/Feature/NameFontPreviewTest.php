<?php

namespace Tests\Feature;

use App\Services\DesignFonts;
use App\Services\NameFontPreview;
use App\Services\NameTextRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NameFontPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function privateFont(): void
    {
        Storage::fake('local');
        config(['design_fonts.Private Test' => ['label' => 'Private font', 'languages' => ['ar', 'en'], 'server_rendered' => true, 'files' => [['file' => 'private.woff2', 'weight' => '400']]]]);
        Storage::disk('local')->put('design-fonts/private.woff2', 'private font fixture');
    }

    private function document(string $font = 'Private Test'): array
    {
        return ['sides' => ['front' => ['elements' => [['id' => 'name-front', 'type' => 'text', 'text' => 'محمد علي', 'font' => $font, 'width' => 61.6, 'height' => 12, 'fontSize' => 4.5, 'color' => '#30262B', 'align' => 'middle', 'fitToWidth' => true]]]]];
    }

    public function test_font_choices_hide_private_fonts_until_their_files_are_installed(): void
    {
        $this->privateFont();
        $this->assertArrayHasKey('Private Test', DesignFonts::choices());
        Storage::disk('local')->delete('design-fonts/private.woff2');

        $this->assertArrayNotHasKey('Private Test', DesignFonts::choices());
        $this->assertArrayHasKey('Cairo', DesignFonts::choices());
    }

    public function test_private_font_preview_is_an_image_and_reuses_the_cached_render(): void
    {
        $this->privateFont();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
        $this->mock(NameTextRenderer::class)->shouldReceive('render')->once()->withArgs(function (array $element, array $fonts, string $directory) use ($png): bool {
            Storage::disk('local')->put($directory.'/name.png', $png);

            return $element['text'] === 'محمد علي' && $fonts === [] && str_starts_with($directory, 'name-previews/');
        });
        $preview = app(NameFontPreview::class);

        $first = $preview->images($this->document());
        $second = $preview->images($this->document());

        $this->assertSame(['name-front' => 'data:image/png;base64,'.base64_encode($png)], $first);
        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('private font fixture', json_encode($first));
        $this->get('/storage/design-fonts/private.woff2')->assertNotFound();
    }

    public function test_public_fonts_do_not_invoke_the_server_name_renderer(): void
    {
        $this->mock(NameTextRenderer::class)->shouldNotReceive('render');

        $this->assertSame([], app(NameFontPreview::class)->images($this->document('Cairo')));
    }

    public function test_private_font_bytes_are_frozen_and_preview_checks_the_frozen_checksum(): void
    {
        $this->privateFont();
        $document = $this->document();
        $fonts = DesignFonts::freeze($document, 'font-test-order');
        $file = $fonts['Private Test']['files'][0];
        $this->assertTrue($fonts['Private Test']['server_rendered']);
        $this->assertSame('private font fixture', Storage::disk('local')->get($file['path']));
        Storage::disk('local')->put($file['path'], 'tampered font');
        $this->mock(NameTextRenderer::class)->shouldNotReceive('render');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Frozen font checksum mismatch.');

        app(NameFontPreview::class)->images($document, $fonts);
    }

    public function test_retention_cleans_old_name_previews_and_preserves_private_font_programs(): void
    {
        $this->privateFont();
        $disk = Storage::disk('local');
        $disk->put('name-previews/old/name.png', 'old preview');
        $disk->put('name-previews/recent/name.png', 'recent preview');
        touch($disk->path('name-previews/old/name.png'), now()->subDays(100)->getTimestamp());

        $this->artisan('sticker:purge', ['--force' => true])->assertSuccessful();

        $disk->assertMissing('name-previews/old/name.png');
        $disk->assertExists('name-previews/recent/name.png');
        $disk->assertExists('design-fonts/private.woff2');
    }
}
