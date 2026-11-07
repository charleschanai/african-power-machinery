<?php

namespace Tests\Feature;

use App\Models\EntryLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * User administration.
 *
 * The behaviour that matters here is not CRUD, it is the refusal to leave
 * the system unadministrable. Every "cannot" case below exists because the
 * alternative is unrecoverable without direct database access.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /* ------------------------------------------------------------------ */
    /*  Listing                                                            */
    /* ------------------------------------------------------------------ */

    public function test_admin_can_list_users_with_entry_counts(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);
        EntryLog::factory()->create(['processed_by' => $attendant->id]);

        $response = $this->getJson('/api/v1/users');

        $response->assertOk()->assertJsonPath('success', true);

        $rows = collect($response->json('data.data'))->keyBy('id');

        $this->assertTrue($rows->has($attendant->id));
        $this->assertSame(1, $rows[$attendant->id]['processed_entries_count']);
        $this->assertTrue($rows[$attendant->id]['is_active']);
    }

    public function test_user_list_never_leaks_secrets(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $row = $this->getJson('/api/v1/users')->json('data.data.0');

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function test_user_list_can_filter_and_search(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        User::factory()->create(['name' => 'Zawadi Mtumiaji', 'role' => 'staff']);
        User::factory()->create(['name' => 'Mkulima Mtumiaji', 'role' => 'staff']);
        $secondAdmin = User::factory()->admin()->create(['name' => 'Msimamizi Mwingine']);
        $secondAdmin->update(['is_active' => false]);

        $this->getJson('/api/v1/users?q=Mkulima')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Mkulima Mtumiaji');

        $this->getJson('/api/v1/users?role=admin')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/v1/users?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $secondAdmin->id);
    }

    public function test_staff_cannot_reach_user_administration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']), 'sanctum');

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_guest_cannot_reach_user_administration(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ */
    /*  Creating                                                           */
    /* ------------------------------------------------------------------ */

    public function test_admin_can_create_a_user(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Mhudumu wa Mlangoni',
            'email' => 'gate@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User added.')
            ->assertJsonPath('data.name', 'Mhudumu wa Mlangoni')
            ->assertJsonPath('data.is_active', true);

        $created = User::where('email', 'gate@example.com')->first();

        $this->assertNotNull($created);
        $this->assertSame('staff', $created->role);
        // The cast must hash it, not store the literal string.
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $created->password));
    }

    public function test_create_validates_input(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/users', [
            'name' => '', 'email' => 'not-an-email', 'password' => 'short',
            'password_confirmation' => 'mismatch', 'role' => 'wizard',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate', 'email' => 'taken@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'staff',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    /* ------------------------------------------------------------------ */
    /*  Editing                                                            */
    /* ------------------------------------------------------------------ */

    public function test_admin_can_update_a_user_without_touching_the_password(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);
        $originalHash = $attendant->password;

        $this->putJson('/api/v1/users/'.$attendant->id, [
            'name' => 'Jina Jipya',
            'email' => $attendant->email,
            'role' => 'admin',
        ])->assertOk()
            ->assertJsonPath('message', 'User updated.')
            ->assertJsonPath('data.name', 'Jina Jipya')
            ->assertJsonPath('data.role', 'admin');

        $attendant->refresh();

        $this->assertSame('Jina Jipya', $attendant->name);
        $this->assertSame('admin', $attendant->role);
        $this->assertSame($originalHash, $attendant->password, 'Editing a role must not reset the password.');
    }

    public function test_update_ignores_its_own_row_for_email_uniqueness(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        $this->putJson('/api/v1/users/'.$admin->id, [
            'name' => 'Renamed Admin',
            'email' => $admin->email,
            'role' => 'admin',
        ])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  Lockout guards -- the reason this controller exists                */
    /* ------------------------------------------------------------------ */

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        $this->deleteJson('/api/v1/users/'.$admin->id)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SELF_LOCKOUT');

        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        $this->putJson('/api/v1/users/'.$admin->id, [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'staff',
        ])->assertStatus(422)->assertJsonPath('error_code', 'SELF_LOCKOUT');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_an_admin_may_still_manage_another_admin(): void
    {
        $first = $this->admin();
        $second = User::factory()->admin()->create();
        $this->actingAs($second, 'sanctum');

        // Two active admins exist, so the lockout guards must not fire. If
        // they did, an install could never be reduced back to one admin.
        $this->deleteJson('/api/v1/users/'.$first->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($first->fresh()->isActive());
        $this->assertTrue($second->fresh()->isActive());
    }

    public function test_managing_an_already_deactivated_admin_is_allowed(): void
    {
        $acting = $this->admin();
        $retired = User::factory()->admin()->create();
        $retired->update(['is_active' => false]);

        $this->actingAs($acting, 'sanctum');

        // A retired admin has no access to lose, so this must not be treated
        // as removing the last admin.
        $this->putJson('/api/v1/users/'.$retired->id, [
            'name' => $retired->name, 'email' => $retired->email, 'role' => 'staff',
        ])->assertOk()->assertJsonPath('data.role', 'staff');
    }

    public function test_a_deactivated_admin_cannot_act_on_a_stale_token(): void
    {
        $admin = $this->admin();
        $other = User::factory()->admin()->create();
        $token = $other->createToken('their-session')->plainTextToken;

        // Deactivate them through a different admin, then replay the token.
        $this->actingAs($admin, 'sanctum');
        $this->deleteJson('/api/v1/users/'.$other->id)->assertOk();

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $other->id)->count());
        $this->assertNotNull($token);
    }

    /* ------------------------------------------------------------------ */
    /*  Deactivation, not deletion                                        */
    /* ------------------------------------------------------------------ */

    public function test_delete_deactivates_rather_than_removing_the_row(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);

        $this->deleteJson('/api/v1/users/'.$attendant->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $attendant->id,
            'is_active' => false,
        ]);
    }

    public function test_deactivating_keeps_the_entry_history_attributable(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);
        $log = EntryLog::factory()->create(['processed_by' => $attendant->id]);

        $this->deleteJson('/api/v1/users/'.$attendant->id)->assertOk();

        // The row survives, so the log still points at a real person.
        $this->assertDatabaseHas('entry_logs', [
            'id' => $log->id,
            'processed_by' => $attendant->id,
        ]);
    }

    public function test_deactivating_revokes_existing_sessions(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);
        $attendant->createToken('their-session');

        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $attendant->id)->count());

        $this->deleteJson('/api/v1/users/'.$attendant->id)->assertOk();

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $attendant->id)->count());
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'is_active' => false]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_reactivate_a_user(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $user = User::factory()->create(['role' => 'staff', 'is_active' => false]);

        $this->postJson('/api/v1/users/'.$user->id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  Password reset                                                     */
    /* ------------------------------------------------------------------ */

    public function test_reset_password_works_and_logs_the_target_out(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);
        $attendant->createToken('their-session');

        $this->postJson('/api/v1/users/'.$attendant->id.'/reset-password', [
            'password' => 'brand-new-123',
            'password_confirmation' => 'brand-new-123',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brand-new-123', $attendant->fresh()->password));
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $attendant->id)->count());

        $this->postJson('/api/v1/login', [
            'email' => $attendant->email,
            'password' => 'brand-new-123',
        ])->assertOk();
    }

    public function test_reset_password_requires_a_matching_confirmation(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);

        $this->postJson('/api/v1/users/'.$attendant->id.'/reset-password', [
            'password' => 'brand-new-123',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    /* ------------------------------------------------------------------ */
    /*  Audit trail                                                        */
    /* ------------------------------------------------------------------ */

    public function test_user_changes_are_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        $attendant = User::factory()->create(['role' => 'staff']);
        $this->deleteJson('/api/v1/users/'.$attendant->id)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.deactivate',
            'user_id' => $admin->id,
        ]);
    }
}
