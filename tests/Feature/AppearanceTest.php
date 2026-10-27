<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_mode_is_the_default_and_the_switch_is_available(): void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('data-theme="light"', false)->assertSee('data-theme-toggle', false)->assertSee('content="#FFFFFF"', false)->assertSee('aria-label="النمط النهاري" aria-pressed="true"', false)->assertSee('STICKER')->assertSee('FIGHT');
    }

    public function test_night_preference_is_rendered_before_javascript_across_store_and_admin_pages(): void
    {
        $this->seed();
        $this->withUnencryptedCookie('sf_theme', 'dark');
        foreach (['/', '/designs', '/editor', '/cart'] as $path) {
            $this->get($path)->assertOk()->assertSee('data-theme="dark"', false)->assertSee('content="#1C1B20"', false)->assertSee('aria-label="النمط النهاري" aria-pressed="false"', false);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin')->assertOk()->assertSee('data-theme="dark"', false)->assertSee('data-theme-toggle', false);
    }

    #[TestWith(['system'])]
    #[TestWith(['<script>alert(1)</script>'])]
    public function test_an_invalid_theme_cookie_falls_back_to_day_without_rendering_its_value(string $value): void
    {
        $this->seed();
        $this->withUnencryptedCookie('sf_theme', $value)->get('/')->assertOk()->assertSee('data-theme="light"', false)->assertDontSee($value, false);
    }
}
