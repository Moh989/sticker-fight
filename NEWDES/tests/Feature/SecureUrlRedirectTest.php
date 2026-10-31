<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SecureUrlRedirectTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['https://stickerfight.test', false])]
    #[TestWith(['http://stickerfight.test', true])]
    public function test_https_deployments_keep_guest_admin_redirects_secure_behind_an_http_origin(string $appUrl, bool $secureCookie): void
    {
        config(['app.url' => $appUrl, 'session.secure' => $secureCookie]);

        $this->get('http://stickerfight.test/admin')->assertRedirect('https://stickerfight.test/login');
    }

    public function test_admin_login_redirects_to_https_and_keeps_the_session_cookie_secure(): void
    {
        config(['app.url' => 'https://stickerfight.test', 'session.secure' => true]);
        $password = 'test-admin-password-2026';
        $admin = User::factory()->create(['role' => 'admin', 'password' => $password]);

        $response = $this->post('http://stickerfight.test/login', ['email' => $admin->email, 'password' => $password]);

        $response->assertRedirect('https://stickerfight.test/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($response->getCookie(config('session.cookie'), false)->isSecure());
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        $this->app['auth']->forgetGuards();
        $this->get('https://stickerfight.test/admin/manage/products')->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_saving_catalog_art_redirects_to_https_and_preserves_admin_access(): void
    {
        Storage::fake('local');
        config(['app.url' => 'https://stickerfight.test', 'session.secure' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $response = $this->post('http://stickerfight.test/admin/manage/products', ['name' => 'Secure upload', 'slug' => 'secure-upload', 'active' => 1, 'art_file' => UploadedFile::fake()->image('catalog.png', 1200, 760)]);

        $product = Product::firstOrFail();
        $response->assertRedirect('https://stickerfight.test/admin/manage/products/'.$product->id)->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($product->catalogAsset->path);
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        $this->get('https://stickerfight.test/admin/manage/products/'.$product->id)->assertOk()->assertSee('action="https://stickerfight.test/admin/manage/products/'.$product->id.'"', false);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_upload_validation_redirects_old_http_session_history_to_https_and_preserves_errors(): void
    {
        Storage::fake('local');
        config(['app.url' => 'https://stickerfight.test', 'session.secure' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession(['_previous' => ['url' => 'http://stickerfight.test/admin/manage/products']]);

        $response = $this->post('http://stickerfight.test/admin/manage/products', ['name' => 'Oversized art', 'slug' => 'oversized-art', 'active' => 1, 'art_file' => UploadedFile::fake()->image('catalog.png', 1200, 760)->size(4097)]);

        $response->assertRedirect('https://stickerfight.test/admin/manage/products')->assertSessionHasErrors(['art_file' => 'الحد الأعلى لصورة الكتالوج 4 ميغابايت.']);
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        $this->get('https://stickerfight.test/admin/manage/products')->assertOk()->assertSee('الحد الأعلى لصورة الكتالوج 4 ميغابايت.');
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('assets', 0);
    }

    #[TestWith(['http://stickerfight.test/admin/manage/products?q=art%20work#preview', 'https://stickerfight.test/admin/manage/products?q=art%20work#preview'])]
    #[TestWith(['http://other.example/return', 'http://other.example/return'])]
    #[TestWith(['https://stickerfight.test/admin', 'https://stickerfight.test/admin'])]
    #[TestWith(['http://stickerfight.test:80/admin', 'https://stickerfight.test/admin'])]
    #[TestWith(['http://stickerfight.test:8443/admin', 'https://stickerfight.test:8443/admin'])]
    public function test_https_normalization_preserves_redirect_paths_queries_and_external_destinations(string $target, string $expected): void
    {
        config(['app.url' => 'https://stickerfight.test', 'session.secure' => true]);
        Route::get('/test-redirect', fn () => redirect()->away($target));

        $this->get('http://stickerfight.test/test-redirect')->assertRedirect($expected);
    }

    public function test_local_http_development_keeps_http_redirects(): void
    {
        config(['app.url' => 'http://localhost', 'session.secure' => false]);

        $this->get('http://localhost/admin')->assertRedirect('http://localhost/login');
    }
}
