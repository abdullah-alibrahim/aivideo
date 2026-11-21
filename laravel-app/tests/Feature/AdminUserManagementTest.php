<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_uses_the_saved_interface_language_without_a_query_parameter(): void
    {
        config(['studio.admin_email' => 'admin@ai.yellowspace.online']);
        $admin = User::factory()->create(['email' => 'admin@ai.yellowspace.online']);

        $this->actingAs($admin)
            ->withSession(['app_locale' => 'en'])
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr"', false)
            ->assertSee('Manage users');
    }

    public function test_configured_admin_can_create_a_verified_user_with_a_hashed_password(): void
    {
        config(['studio.admin_email' => 'admin@ai.yellowspace.online']);
        $admin = User::factory()->create(['email' => 'admin@ai.yellowspace.online']);

        $this->actingAs($admin)
            ->get('/admin/users?lang=en')
            ->assertOk()
            ->assertSee('Create user')
            ->assertSee('Password confirmation');

        $response = $this->post('/admin/users?lang=en', [
            'email' => 'new.member@example.com',
            'password' => 'a-strong-password-2026',
            'password_confirmation' => 'a-strong-password-2026',
        ]);

        $response->assertRedirect();
        $user = User::query()->where('email', 'new.member@example.com')->firstOrFail();
        $this->assertSame('new.member', $user->name);
        $this->assertTrue(Hash::check('a-strong-password-2026', $user->password));
        $this->assertNotNull($user->email_verified_at);

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    public function test_non_admin_cannot_open_user_management_or_create_users(): void
    {
        config(['studio.admin_email' => 'admin@ai.yellowspace.online']);
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($user->fresh())
            ->get('/admin/users')
            ->assertForbidden();

        $this->post('/admin/users', [
            'email' => 'another.member@example.com',
            'password' => 'a-strong-password-2026',
            'password_confirmation' => 'a-strong-password-2026',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'another.member@example.com']);
    }

    public function test_admin_user_creation_rejects_duplicate_emails_and_unconfirmed_passwords(): void
    {
        config(['studio.admin_email' => 'admin@ai.yellowspace.online']);
        $admin = User::factory()->create(['email' => 'admin@ai.yellowspace.online']);
        User::factory()->create(['email' => 'existing@example.com']);

        $this->actingAs($admin)
            ->from('/admin/users')
            ->post('/admin/users', [
                'email' => 'existing@example.com',
                'password' => 'a-strong-password-2026',
                'password_confirmation' => 'a-different-password',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_user_creation_rejects_email_duplicates_with_different_casing(): void
    {
        config(['studio.admin_email' => 'admin@ai.yellowspace.online']);
        $admin = User::factory()->create(['email' => 'admin@ai.yellowspace.online']);
        User::factory()->create(['email' => 'Existing@Example.com']);

        $this->actingAs($admin)
            ->from('/admin/users')
            ->post('/admin/users', [
                'email' => 'existing@example.com',
                'password' => 'a-strong-password-2026',
                'password_confirmation' => 'a-strong-password-2026',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_can_suspend_and_reactivate_a_user_without_email_verification(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.suspension', $user))
            ->assertRedirect(route('admin.users', ['lang' => 'ar']));

        $this->assertNotNull($user->fresh()->suspended_at);
        $this->actingAs($user->fresh())
            ->get('/studio')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)
            ->patch(route('admin.users.suspension', $user))
            ->assertRedirect(route('admin.users', ['lang' => 'ar']));

        $this->assertNull($user->fresh()->suspended_at);
        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(url('/email/verify'));
    }

    public function test_admin_can_soft_delete_and_restore_a_user_without_losing_account_data(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users', ['lang' => 'ar']));

        $this->assertSoftDeleted($user);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseMissing('users', ['id' => $user->id, 'deleted_at' => null]);

        $this->actingAs($admin)
            ->patch(route('admin.users.restore', $user->id))
            ->assertRedirect(route('admin.users', ['lang' => 'ar']));

        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_admin_cannot_suspend_or_delete_themselves(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.users.suspension', $admin))
            ->assertForbidden();

        $this->delete(route('admin.users.destroy', $admin))
            ->assertForbidden();

        $this->assertNull($admin->fresh()->suspended_at);
        $this->assertFalse($admin->fresh()->trashed());
    }

    public function test_admin_can_manage_another_administrator(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.users.suspension', $otherAdmin))
            ->assertRedirect(route('admin.users', ['lang' => 'ar']));

        $this->assertNotNull($otherAdmin->fresh()->suspended_at);
    }
}
