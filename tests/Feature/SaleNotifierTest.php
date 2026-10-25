<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Notifications\SaleCreated;
use App\Notifications\SaleDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SaleNotifierTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->actingAs($this->admin);
        Notification::fake();
    }

    protected function configureNotifications(): void
    {
        Setting::create(['meta_key' => 'mail_settings', 'meta_value' => json_encode(['admin_email' => 'owner@example.com'])]);
        Setting::create(['meta_key' => 'telegram_settings', 'meta_value' => json_encode(['chat_id' => '123', 'token' => 'abc'])]);
    }

    protected function sale(): int
    {
        $stock = $this->stockFor();

        return $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)]])->json('sale_id');
    }

    public function test_sale_notification_goes_to_mail_and_telegram(): void
    {
        $this->configureNotifications();
        $saleId = $this->sale();

        $this->getJson("/sale-notification/{$saleId}")->assertOk()->assertJson(['success' => 'Notification sent successfully']);

        Notification::assertSentTo(new AnonymousNotifiable, SaleCreated::class, fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'owner@example.com');
        Notification::assertSentTo(new AnonymousNotifiable, SaleCreated::class, fn ($n, $channels, $notifiable) => ($notifiable->routes['telegram'] ?? null) === '123');
    }

    public function test_sale_notification_without_mail_settings(): void
    {
        $saleId = $this->sale();

        $this->getJson("/sale-notification/{$saleId}")->assertStatus(404)->assertJson(['error' => 'Admin mail settings not found']);
        Notification::assertNothingSent();
    }

    public function test_deleting_a_sale_notifies_the_owner(): void
    {
        $this->configureNotifications();
        $saleId = $this->sale();

        $this->deleteJson("/sales/{$saleId}")->assertOk();

        Notification::assertSentTo(new AnonymousNotifiable, SaleDeleted::class, fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'owner@example.com');
        Notification::assertSentTo(new AnonymousNotifiable, SaleDeleted::class, fn ($n, $channels, $notifiable) => ($notifiable->routes['telegram'] ?? null) === '123');
    }
}
