<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\App;
use App\Models\Setting;
use App\Settings\SettingsRepository;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Migrations can add or change settings rows without going through the model
        Event::listen(MigrationsEnded::class, fn () => app(SettingsRepository::class)->flush());

        LogViewer::auth(function ($request) {
            /** @var \App\Models\User */
            $user = Auth::user();
            return $user && $user->hasRole('super-admin');
        });

        // It's possible that the database hasn't been migrated yet, so we need to check that the setting exists before trying to retrieve it.
        if (!App::runningInConsole()) {
            $mailSetting = Setting::where('meta_key', 'mail_settings')->first();
        } else {
            $mailSetting = null;
        }
        if ($mailSetting) {
            $mailSettings = json_decode($mailSetting->meta_value);
            Config::set(['mail.driver' => 'smtp']);
            Config::set(['mail.host' => $mailSettings->mail_host]);
            Config::set(['mail.port' => $mailSettings->mail_port]);
            Config::set(['mail.username' => $mailSettings->mail_username]);
            Config::set(['mail.password' => $mailSettings->mail_password]);
            Config::set(['mail.encryption' => $mailSettings->mail_encryption]);
            Config::set(['mail.from.address' => $mailSettings->mail_from_address]);
            Config::set(['mail.from.name' => $mailSettings->mail_from_name]);
        }
    }
}
