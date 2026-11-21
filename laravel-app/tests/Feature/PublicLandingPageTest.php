<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_is_public_and_shows_signup_and_local_video_preview(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('href="'.url('/register').'"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('/videos/landing-nature.webm', false)
            ->assertSee('/images/landing-nature-poster.jpg', false);
    }

    public function test_language_choice_is_saved_and_invalid_language_is_ignored(): void
    {
        $this->get('/?lang=en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false);

        $this->get('/?lang=fr')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false);
    }

    public function test_authenticated_users_are_sent_to_the_studio_and_guests_cannot_open_it(): void
    {
        $this->assertSame(url('/studio'), route('reels.index'));

        $this->get('/studio')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('reels.index'));
    }
}
