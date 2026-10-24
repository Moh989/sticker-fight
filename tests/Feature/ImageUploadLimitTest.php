<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ImageUploadLimitTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([13312])]
    #[TestWith([14336])]
    public function test_personal_images_up_to_fourteen_megabytes_keep_their_full_dimensions(int $kilobytes): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('portrait.png', 400, 800)->size($kilobytes);

        $response = $this->post('/api/assets', ['file' => $image, 'rights' => true], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('width', 400)->assertJsonPath('height', 800);
        $asset = Asset::findOrFail($response->json('id'));
        $this->assertSame('upload', $asset->source);
        $this->assertSame(400, $asset->width);
        $this->assertSame(800, $asset->height);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_images_above_fourteen_megabytes_are_rejected_without_storing_an_asset(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('large.png', 400, 800)->size(14337);

        $this->post('/api/assets', ['file' => $image, 'rights' => true], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'الحد الأعلى للصورة 14 ميغابايت.');

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
