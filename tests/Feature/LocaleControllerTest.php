<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_switch_returns_to_the_open_page_after_a_background_404(): void
    {
        $this->get('/designs?q=orbit&sort=name')->assertOk();
        $this->get('/privacy')->assertNotFound();

        $this->get('/language/en?'.http_build_query(['return_to' => '/designs?q=orbit&sort=name']))
            ->assertRedirect('/designs?q=orbit&sort=name')
            ->assertSessionHas('locale', 'en');

        $this->get('/designs?q=orbit&sort=name')->assertOk()->assertSee('lang="en" dir="ltr"', false);
        $this->get('/language/ar?'.http_build_query(['return_to' => '/designs?q=orbit&sort=name']))
            ->assertRedirect('/designs?q=orbit&sort=name')
            ->assertSessionHas('locale', 'ar');
    }

    #[DataProvider('invalidReturnPaths')]
    public function test_untrusted_or_recursive_language_return_paths_fall_back_to_home(mixed $returnTo): void
    {
        $this->get('/language/en?'.http_build_query(['return_to' => $returnTo]))
            ->assertRedirect('/')
            ->assertSessionHas('locale', 'en');
    }

    public static function invalidReturnPaths(): array
    {
        return [
            'external' => ['https://example.test/designs'],
            'protocol relative' => ['//example.test/designs'],
            'encoded protocol relative' => ['/%2fexample.test/designs'],
            'backslash' => ['/\\example.test/designs'],
            'encoded backslash' => ['/%5cexample.test/designs'],
            'control characters' => ["/designs\r\nLocation: https://example.test"],
            'array' => [['/designs']],
            'recursive switch' => ['/language/ar'],
        ];
    }

    public function test_old_language_links_without_a_return_path_open_home_instead_of_session_history(): void
    {
        $this->withSession(['_previous' => ['url' => url('/privacy')]])
            ->get('/language/en')
            ->assertRedirect('/')
            ->assertSessionHas('locale', 'en');
    }

    public function test_unsupported_language_returns_404_without_changing_the_locale(): void
    {
        $this->withSession(['locale' => 'ar'])->get('/language/unknown')
            ->assertNotFound()
            ->assertSessionHas('locale', 'ar');
    }
}
