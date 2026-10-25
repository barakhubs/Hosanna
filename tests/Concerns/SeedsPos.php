<?php

namespace Tests\Concerns;

use App\Models\Contact;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait SeedsPos
{
    protected Store $store;

    protected Contact $customer;

    protected User $admin;

    protected array $permissionNames = [
        'pos', 'products', 'inventory', 'sales', 'customers', 'vendors', 'collections',
        'expenses', 'quotations', 'reloads', 'cheques', 'sold-items', 'purchases',
        'payments', 'stores', 'employees', 'payroll', 'media', 'settings',
    ];

    protected function seedPos(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        Role::firstOrCreate(['name' => 'super-admin'])->givePermissionTo(Permission::all());
        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo(Permission::all());
        Role::firstOrCreate(['name' => 'user'])->givePermissionTo(['pos']);

        $this->store = Store::factory()->create(['id' => 1]);

        foreach ([
            'shop_name' => 'Test Shop',
            'shop_logo' => 'logo.png',
            'sale_receipt_note' => 'Thank you',
            'modules' => 'Inventory',
            'misc_settings' => json_encode(['cart_first_focus' => 'quantity', 'product_alert' => 3]),
            'currency_settings' => json_encode(['currency_symbol' => 'UGX']),
        ] as $key => $value) {
            Setting::create(['meta_key' => $key, 'meta_value' => $value]);
        }

        $this->customer = Contact::factory()->create(['name' => 'Walk-in']);
        $this->admin = $this->makeUser('admin');
    }

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create(['user_role' => $role]);
        $user->assignRole($role);

        return $user;
    }

    protected function stockFor(array $batchAttributes = [], float $quantity = 10, array $productAttributes = []): ProductStock
    {
        $product = \App\Models\Product::factory()->create($productAttributes);
        $batch = \App\Models\ProductBatch::factory()->create(['product_id' => $product->id] + $batchAttributes);

        return ProductStock::factory()->create([
            'store_id' => $this->store->id,
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    }
}
