<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'حساب فريق', 'email' => 'new-staff@example.test', 'password' => 'unique-staff-password', 'password_confirmation' => 'unique-staff-password', 'role' => 'orders'], $overrides);
    }

    #[TestWith(['admin'])]
    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    #[TestWith(['shipping'])]
    #[TestWith(['customer'])]
    public function test_admin_creates_an_account_with_the_selected_role_and_keeps_their_session(string $role): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = $this->payload(['role' => $role, 'email' => 'NEW-STAFF@example.test', 'email_verified_at' => now()->toISOString(), 'remember_token' => 'injected']);
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('data-create-user-form', false)->assertSee(route('admin.users.store'), false)->assertSee('إضافة مستخدم');

        $this->post('/admin/users', $payload)->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors()->assertSessionHas('success');

        $created = User::where('email', 'new-staff@example.test')->firstOrFail();
        $this->assertSame($role, $created->role);
        $this->assertSame('حساب فريق', $created->name);
        $this->assertTrue(Hash::check('unique-staff-password', $created->password));
        $this->assertNotSame('unique-staff-password', $created->password);
        $this->assertNull($created->email_verified_at);
        $this->assertNull($created->remember_token);
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('users', 2);
        $event = AuditLog::where('action', 'user.created')->firstOrFail();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame((string) $created->id, $event->subject_id);
        $this->assertSame(['role' => $role], $event->changes);
        $this->assertStringNotContainsString('unique-staff-password', $event->toJson());
    }

    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    #[TestWith(['shipping'])]
    #[TestWith(['customer'])]
    public function test_only_admin_can_create_accounts(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->postJson('/admin/users', $this->payload(['role' => 'admin']))->assertForbidden();
        $this->get('/admin/users')->assertForbidden();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_guest_is_redirected_to_login_without_creating_an_account(): void
    {
        $this->post('/admin/users', $this->payload())->assertRedirect('/login');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_missing_fields_are_rejected_without_creating_an_account(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/users', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['name', 'أ'])]
    #[TestWith(['email', 'invalid-email'])]
    #[TestWith(['email', ['unexpected-array']])]
    #[TestWith(['password', 'short'])]
    #[TestWith(['password_confirmation', 'does-not-match'])]
    #[TestWith(['role', 'unknown-role'])]
    public function test_invalid_details_are_rejected_without_creating_an_account(string $field, mixed $value): void
    {
        $error = $field === 'password_confirmation' ? 'password' : $field;
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/users', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_email_cannot_replace_an_existing_account_or_its_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $existing = User::factory()->create(['email' => 'existing@example.test', 'role' => 'customer']);
        $password = $existing->password;
        $this->actingAs($admin)->postJson('/admin/users', $this->payload(['email' => 'EXISTING@example.test', 'role' => 'admin']))->assertUnprocessable()->assertJsonPath('errors.email.0', 'هذا البريد مستخدم مسبقاً. اختر بريداً آخر.');
        $this->assertSame('customer', $existing->fresh()->role);
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_validation_preserves_safe_inputs_without_flashing_passwords(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->from('/admin/users')->post('/admin/users', $this->payload(['password_confirmation' => 'different-password']))->assertRedirect('/admin/users')->assertSessionHasErrors('password')->assertSessionHas('_old_input.name', 'حساب فريق')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->get('/admin/users')->assertOk()->assertSee('حساب فريق')->assertDontSee('unique-staff-password', false)->assertDontSee('different-password', false);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unicode_passwords_over_bcrypt_byte_limit_return_a_clear_validation_error(): void
    {
        $password = str_repeat('س', 40);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/users', $this->payload(['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonPath('errors.password.0', 'كلمة المرور طويلة جداً. استخدم كلمة أقصر.');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_new_shipping_user_can_log_in_and_access_their_tasks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/users', $this->payload(['role' => 'shipping']))->assertRedirect();
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => 'new-staff@example.test', 'password' => 'unique-staff-password'])->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect(route('admin.tasks.index'));
        $this->get('/admin/tasks')->assertOk()->assertSee('data-task-lane="shipping"', false)->assertDontSee('data-task-lane="orders"', false);
    }

    public function test_names_are_escaped_in_the_table_and_success_message(): void
    {
        $name = '<script>alert(1)</script>';
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/users', $this->payload(['name' => $name]))->assertRedirect();
        $this->get('/admin/users')->assertOk()->assertSee($name)->assertDontSee($name, false);
    }
}
