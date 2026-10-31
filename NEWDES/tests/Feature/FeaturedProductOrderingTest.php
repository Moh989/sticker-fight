<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FeaturedProductOrderingTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['latest', 'expensive'])]
    #[TestWith(['name', 'regular'])]
    #[TestWith(['popular', 'regular'])]
    #[TestWith(['price_asc', 'regular'])]
    #[TestWith(['price_desc', 'expensive'])]
    public function test_explicit_catalog_sorts_override_feature_priority(string $sort, string $expectedSlug): void
    {
        $featured = Product::create(['name' => 'Z Featured', 'slug' => 'featured', 'art_key' => 'minimal', 'price_iqd' => 8000, 'is_featured' => true, 'created_at' => '2026-01-01']);
        Product::create(['name' => 'A Regular', 'slug' => 'regular', 'art_key' => 'minimal', 'price_iqd' => 1000, 'popularity_count' => 100, 'created_at' => '2026-10-01']);
        Product::create(['name' => 'B Regular', 'slug' => 'expensive', 'art_key' => 'minimal', 'price_iqd' => 20000, 'created_at' => '2026-10-02']);

        $this->get('/designs?sort='.$sort)->assertOk()->assertViewHas('products', fn ($products): bool => $products->first()->slug === $expectedSlug);
    }

    public function test_featured_designs_lead_homepage_and_bestsellers(): void
    {
        $featured = Product::create(['name' => 'Featured', 'slug' => 'featured', 'art_key' => 'minimal', 'is_featured' => true, 'popularity_count' => 11, 'created_at' => '2026-01-01']);
        Product::create(['name' => 'Popular', 'slug' => 'popular', 'art_key' => 'minimal', 'popularity_count' => 1000, 'created_at' => '2026-10-01']);

        $this->get('/')->assertOk()->assertViewHas('products', fn ($products): bool => $products->first()->id === $featured->id)->assertViewHas('best', fn ($products): bool => $products->first()->slug === 'popular');
    }

    public function test_homepage_picks_fill_with_newest_active_card_designs_after_featured_ones(): void
    {
        $featured = Product::create(['name' => 'Featured', 'slug' => 'featured', 'art_key' => 'minimal', 'is_featured' => true, 'created_at' => '2026-01-01']);
        Product::create(['name' => 'Older', 'slug' => 'older', 'art_key' => 'minimal', 'created_at' => '2026-02-01']);
        Product::create(['name' => 'Newer', 'slug' => 'newer', 'art_key' => 'minimal', 'created_at' => '2026-03-01']);
        Product::create(['name' => 'Hidden', 'slug' => 'hidden', 'art_key' => 'minimal', 'active' => false, 'created_at' => '2026-04-01']);
        Product::create(['name' => 'Device', 'slug' => 'device', 'product_section_id' => ProductSection::factory()->create()->id, 'art_key' => 'minimal', 'created_at' => '2026-05-01']);

        $this->get('/')->assertOk()->assertViewHas('products', fn ($products): bool => $products->pluck('slug')->all() === ['featured', 'newer', 'older']);
    }

    public function test_featured_device_products_lead_their_section(): void
    {
        $section = ProductSection::factory()->create();
        $featured = Product::create(['name' => 'Z Featured', 'slug' => 'featured-device', 'product_section_id' => $section->id, 'art_key' => 'minimal', 'is_featured' => true, 'created_at' => '2026-01-01']);
        Product::create(['name' => 'A Recent', 'slug' => 'regular-device', 'product_section_id' => $section->id, 'art_key' => 'minimal', 'created_at' => '2026-10-01']);

        $this->get('/devices/'.$section->slug.'?sort=name')->assertOk()->assertViewHas('products', fn ($products): bool => $products->first()->id === $featured->id);
    }
}
