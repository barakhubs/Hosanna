<?php

namespace App\Settings;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Read access to the settings table, cached as one key => value map.
 * The cache is cleared whenever a Setting is saved or deleted.
 */
class SettingsRepository
{
    public const CACHE_KEY = 'settings.all';

    private ?array $items = null;

    public function all(): array
    {
        return $this->items ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::pluck('meta_value', 'meta_key')->all()
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $items = $this->all();

        return array_key_exists($key, $items) ? $items[$key] : $default;
    }

    /**
     * Same shape as Setting::whereIn('meta_key', $keys)->pluck(...): missing keys are left out.
     */
    public function only(array $keys): array
    {
        return Arr::only($this->all(), $keys);
    }

    /**
     * Decoded JSON value, or null when the key is missing or not valid JSON.
     */
    public function json(string $key): ?array
    {
        $value = $this->get($key);
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    public function misc(): ?array
    {
        return $this->json('misc_settings');
    }

    public function modules(): array
    {
        $value = $this->get('modules');

        return $value === null ? [] : explode(',', $value);
    }

    public function flush(): void
    {
        $this->items = null;
        Cache::forget(self::CACHE_KEY);
    }
}
