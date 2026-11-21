<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_users_cannot_access_studio_actions_or_private_media(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/studio')->assertRedirect(url('/email/verify'));
        $this->actingAs($user)->post('/api/preview')->assertRedirect(url('/email/verify'));
        $this->actingAs($user)->get('/previews/private-preview')->assertRedirect(url('/email/verify'));
    }

    public function test_unverified_administrators_can_login_and_access_the_studio(): void
    {
        $admin = User::factory()->unverified()->create([
            'is_admin' => true,
            'password' => 'a-strong-password',
        ]);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'a-strong-password',
        ])->assertRedirect(route('reels.index'));

        $this->assertAuthenticatedAs($admin);
        $this->get('/studio')->assertOk();
    }

    public function test_unverified_users_can_view_the_notice_resend_email_and_log_out(): void
    {
        $user = User::factory()->unverified()->create();
        Notification::fake();

        $this->actingAs($user)
            ->get('/email/verify')
            ->assertOk()
            ->assertSee('role="status"', false);

        $this->post('/email/verification-notification')
            ->assertRedirect();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_valid_signed_link_verifies_the_user_and_allows_studio_access(): void
    {
        $this->assertTrue(Route::has('verification.verify'));
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->actingAs($user)->get($url)->assertRedirect(route('reels.index'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get('/studio')->assertOk();
    }

    public function test_invalid_or_expired_signed_link_does_not_verify_the_user(): void
    {
        $this->assertTrue(Route::has('verification.verify'));
        $user = User::factory()->unverified()->create();
        $expiredUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->actingAs($user)->get($expiredUrl)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_password_login_redirects_to_notice_without_resending_email(): void
    {
        $user = User::factory()->unverified()->create([
            'password' => 'a-strong-password',
        ]);
        Notification::fake();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'a-strong-password',
        ])->assertRedirect(url('/email/verify'));

        $this->assertAuthenticatedAs($user);
        Notification::assertNothingSent();
    }
}
