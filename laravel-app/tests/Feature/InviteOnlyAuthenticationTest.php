<?php

namespace Tests\Feature;

use App\Models\StudioInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class InviteOnlyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_is_public_and_studio_requires_login(): void
    {
        $this->get('/')->assertOk();
        $this->get('/studio')->assertRedirect(route('login'));
        $this->assertTrue(Route::has('register'));
    }

    public function test_member_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery')]);

        $this->post(route('login.store'), [
            'email' => strtoupper($user->email),
            'password' => 'correct horse battery',
        ])->assertRedirect(route('reels.index'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_create_and_consume_a_hashed_one_time_invitation(): void
    {
        Notification::fake();
        $admin = $this->administrator();
        $this->actingAs($admin)
            ->post(route('admin.invitations.store'), ['email' => 'invitee@example.test'])
            ->assertRedirect(route('admin.invitations'))
            ->assertSessionHas('invitation_url');

        $invitation = StudioInvitation::query()->sole();
        $url = (string) session('invitation_url');
        $token = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);

        $this->get(route('invitations.show', $token))->assertOk();
        $this->post(route('invitations.accept', $token), [
            'name' => 'Invited Member',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(url('/email/verify'));

        $invitee = User::query()->where('email', 'invitee@example.test')->sole();
        $this->assertFalse($invitee->is_admin);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        Notification::assertSentTo($invitee, VerifyEmail::class);
        $this->assertAuthenticated();
        $this->post(route('invitations.accept', $token), [
            'name' => 'Replay',
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertNotFound();
    }

    public function test_expired_and_unknown_invitations_cannot_create_accounts(): void
    {
        $token = Str::random(64);
        StudioInvitation::query()->create([
            'email' => 'expired@example.test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('invitations.show', $token))->assertNotFound();
        $this->post(route('invitations.accept', $token), [
            'name' => 'Expired',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertNotFound();
        $this->get(route('invitations.show', Str::random(64)))->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
    }

    public function test_only_administrators_can_manage_invitations_and_provider_settings(): void
    {
        $member = User::factory()->create();

        $this->get(route('admin.invitations'))->assertRedirect(route('login'));
        $this->actingAs($member)
            ->get(route('admin.invitations'))
            ->assertForbidden();
        $this->get(route('settings.provider-keys'))->assertForbidden();

        $admin = $this->administrator();
        $this->actingAs($admin)
            ->get(route('admin.invitations'))
            ->assertOk();
        $this->get(route('settings.provider-keys'))->assertOk();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_admin_role_cannot_be_assigned_through_mass_assignment(): void
    {
        $user = User::query()->create([
            'name' => 'Untrusted User',
            'email' => 'untrusted@example.test',
            'password' => 'a-strong-password',
            'is_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_cli_bootstrap_creates_an_administrator_and_is_idempotent_when_requested(): void
    {
        $this->artisan('studio:make-admin', ['email' => 'owner@example.test'])
            ->expectsQuestion('Name', 'Studio Owner')
            ->expectsQuestion('Password', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'owner@example.test')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));

        $this->artisan('studio:make-admin', [
            'email' => 'owner@example.test',
            '--if-missing' => true,
        ])->assertExitCode(0);
        $this->assertDatabaseCount('users', 1);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }
}
