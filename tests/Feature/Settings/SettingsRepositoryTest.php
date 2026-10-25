<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function repo(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    public function test_reads_values_and_json(): void
    {
        Setting::create(['meta_key' => 'shop_name', 'meta_value' => 'Shop']);
        Setting::create(['meta_key' => 'misc_settings', 'meta_value' => '{"a":1}']);
        Setting::create(['meta_key' => 'broken', 'meta_value' => '{nope']);
        Setting::create(['meta_key' => 'modules', 'meta_value' => 'Inventory,Reloads']);

        $this->assertSame('Shop', $this->repo()->get('shop_name'));
        $this->assertSame('x', $this->repo()->get('missing', 'x'));
        $this->assertSame(['a' => 1], $this->repo()->misc());
        $this->assertSame(['a' => 1], Setting::getMiscSettings());
        $this->assertNull($this->repo()->json('broken'));
        $this->assertNull($this->repo()->json('missing'));
        $this->assertSame(['Inventory', 'Reloads'], Setting::getModules());
        $this->assertSame(['shop_name' => 'Shop'], $this->repo()->only(['shop_name', 'missing']));
    }

    public function test_missing_modules_and_misc(): void
    {
        $this->assertSame([], Setting::getModules());
        $this->assertNull(Setting::getMiscSettings());
    }

    public function test_settings_are_loaded_once(): void
    {
        Setting::create(['meta_key' => 'shop_name', 'meta_value' => 'Shop']);
        $this->repo()->get('shop_name');

        DB::enableQueryLog();
        $this->repo()->get('shop_name');
        $this->repo()->misc();
        $this->repo()->modules();

        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_saving_or_deleting_a_setting_clears_the_cache(): void
    {
        $setting = Setting::create(['meta_key' => 'shop_name', 'meta_value' => 'Old']);
        $this->assertSame('Old', $this->repo()->get('shop_name'));

        Setting::updateOrCreate(['meta_key' => 'shop_name'], ['meta_value' => 'New']);
        $this->assertSame('New', $this->repo()->get('shop_name'));

        $setting->delete();
        $this->assertNull($this->repo()->get('shop_name'));
    }

    public function test_raw_writes_are_picked_up_after_flush(): void
    {
        Setting::create(['meta_key' => 'shop_name', 'meta_value' => 'Old']);
        $this->repo()->get('shop_name');

        DB::table('settings')->where('meta_key', 'shop_name')->update(['meta_value' => 'Raw']);
        $this->assertSame('Old', $this->repo()->get('shop_name'));

        $this->repo()->flush();
        $this->assertSame('Raw', $this->repo()->get('shop_name'));
    }
}
