<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class RoutePermissionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
    }

    protected function roleWith(string $name, array $permissions)
    {
        Role::create(['name' => $name])->givePermissionTo($permissions);

        return $this->makeUser($name);
    }

    public function test_cashier_can_use_pos_but_not_back_office(): void
    {
        $this->actingAs($this->makeUser('user'));

        $this->get('/pos')->assertOk();
        $this->getJson('/products/search?search_query=a')->assertOk();
        $this->getJson('/api/charges/active')->assertOk();
        $this->get('/dashboard')->assertOk();

        foreach (['/products', '/sales', '/purchases', '/settings', '/customers', '/expenses', '/stores', '/payments/sales'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
        $this->deleteJson('/sales/1')->assertForbidden();
        $this->postJson('/settings-update')->assertForbidden();
    }

    public function test_cashier_can_add_a_customer_from_pos(): void
    {
        $this->actingAs($this->makeUser('user'));

        $this->postJson('/contact', ['name' => 'New Guy', 'type' => 'customer'])->assertStatus(201);
    }

    public function test_permission_is_checked_by_user_role_like_the_menu(): void
    {
        $user = $this->roleWith('accounts', ['payments', 'sold-items']);
        $this->actingAs($user);

        $this->get('/payments/sales')->assertOk();
        $this->get('/sold-items')->assertOk();
        $this->get('/pos')->assertForbidden();
    }

    public function test_user_management_is_admin_only(): void
    {
        $this->actingAs($this->roleWith('manager', Permission::pluck('name')->all()));
        $this->get('/users')->assertForbidden();
        $this->get('/user/role')->assertForbidden();

        $this->actingAs($this->admin)->get('/users')->assertOk();
        $this->actingAs($this->makeUser('super-admin'))->get('/users')->assertOk();
    }

    public function test_system_routes_are_for_admins_only(): void
    {
        $uris = ['/maintenance', '/update', '/api/maintenance/database/migrations', '/clear-cache', '/link-storage', '/backup-now'];

        $this->actingAs($this->roleWith('manager', Permission::pluck('name')->all()));
        foreach ($uris as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
        $this->postJson('/api/maintenance/database/migrate')->assertForbidden();
        $this->postJson('/api/maintenance/database/seed')->assertForbidden();
        $this->postJson('/upload-v2')->assertForbidden();

        $this->actingAs($this->admin)->get('/maintenance')->assertOk();
        $this->actingAs($this->makeUser('super-admin'))->get('/maintenance')->assertOk();
    }

    public function test_debug_routes_are_gone_and_editor_needs_login(): void
    {
        $this->get('/editor')->assertRedirect('/login');

        $this->actingAs($this->admin);
        $this->get('/pos/debug')->assertNotFound();
        $this->get('/check-update')->assertNotFound();
    }
}
