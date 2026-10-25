<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Settings
 * @package App\Models
 */

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['meta_key', 'meta_value'];

    protected static function booted()
    {
        static::saved(fn () => app(SettingsRepository::class)->flush());
        static::deleted(fn () => app(SettingsRepository::class)->flush());
    }

    public static function getMiscSettings()
    {
        return app(SettingsRepository::class)->misc();
    }

    /**
     * Retrieve modules as an array by splitting the comma-separated string
     *
     * @return array
     */
    public static function getModules()
    {
        return app(SettingsRepository::class)->modules();
    }
}
