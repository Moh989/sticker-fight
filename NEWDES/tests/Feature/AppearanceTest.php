<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_newdes_uses_the_warm_day_identity(): void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('data-theme="light"', false)->assertDontSee('data-theme-toggle', false)->assertSee('content="#FAF9F6"', false)->assertSee('sticker fight');
    }

    public function test_old_theme_preferences_do_not_override_the_newdes_day_identity(): void
    {
        $this->seed();
        $this->withUnencryptedCookie('sf_theme', 'dark');
        foreach (['/', '/designs', '/editor', '/cart'] as $path) {
            $this->get($path)->assertOk()->assertSee('data-theme="light"', false)->assertSee('content="#FAF9F6"', false)->assertDontSee('data-theme-toggle', false);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin')->assertOk()->assertSee('data-theme="light"', false)->assertDontSee('data-theme-toggle', false);
    }

    public function test_live_store_removes_demo_banner_and_exposes_public_product_offers(): void
    {
        $this->seed();
        config(['sticker.demo_mode' => false]);
        StoreSettings::put('price_single_iqd', 12000);

        $this->get('/')->assertOk()->assertDontSee('class="demo-banner"', false)->assertDontSee('noindex,nofollow', false);
        $this->get('/products/afterhours')->assertOk()->assertSee('"@type":"Offer"', false)->assertSee('"price":12000', false);
        $this->get('/saved')->assertOk()->assertSee('noindex,nofollow', false);
    }

    public function test_demo_store_keeps_the_warning_and_hides_commercial_offers(): void
    {
        $this->seed();
        config(['sticker.demo_mode' => true]);
        StoreSettings::put('price_single_iqd', 12000);

        $this->get('/')->assertOk()->assertSee('class="demo-banner"', false)->assertSee('noindex,nofollow', false);
        $this->get('/products/afterhours')->assertOk()->assertDontSee('"@type":"Offer"', false);
    }

    #[TestWith(['system'])]
    #[TestWith(['<script>alert(1)</script>'])]
    public function test_an_invalid_theme_cookie_falls_back_to_day_without_rendering_its_value(string $value): void
    {
        $this->seed();
        $this->withUnencryptedCookie('sf_theme', $value)->get('/')->assertOk()->assertSee('data-theme="light"', false)->assertDontSee($value, false);
    }
}
