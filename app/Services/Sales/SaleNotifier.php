<?php

namespace App\Services\Sales;

use App\Notifications\SaleCreated;
use App\Notifications\SaleDeleted;
use App\Settings\SettingsRepository;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the shop owner about sales by Telegram and email, using the
 * telegram_settings and mail_settings saved in Settings.
 */
class SaleNotifier
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    /**
     * Returns false when no admin email is set up (Telegram is still sent if configured).
     */
    public function created($sale): bool
    {
        return $this->send(
            fn ($token) => new SaleCreated($sale, $token),
            fn () => new SaleCreated($sale),
        );
    }

    public function deleted($sale): bool
    {
        return $this->send(
            fn ($token) => new SaleDeleted($sale, $token),
            fn () => new SaleDeleted($sale),
        );
    }

    private function send(callable $telegramNotification, callable $mailNotification): bool
    {
        $telegramSettings = $this->settings->get('telegram_settings');
        if ($telegramSettings) {
            $telegramSettings = json_decode($telegramSettings, true);
            Notification::route('telegram', $telegramSettings['chat_id'])
                ->notify($telegramNotification($telegramSettings['token']));
        }

        $adminMail = $this->settings->get('mail_settings');
        if (!$adminMail) {
            return false;
        }

        $adminMail = json_decode($adminMail, true);
        Notification::route('mail', $adminMail['admin_email'])->notify($mailNotification());

        return true;
    }
}
