<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\NewOrderStoreOwnerNotification;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Tests\TestCase;

final class NewOrderStoreOwnerNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory', 'crm', 'ecosystem'];

    /**
     * Regression for KANVAS-ECOSYSTEM-5GF: a stale per-tenant `new-order-store-owner`
     * template referencing `$order`/`$admin` (the pre-rename variable names) fataled with
     * "Undefined variable $admin" because the notification only exposed `$entity`/`$user`.
     * getData() must now surface both name pairs so those templates render on the queue.
     */
    public function test_exposes_order_and_admin_aliases_so_legacy_templates_render(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $notification = new NewOrderStoreOwnerNotification(
            $order,
            [
                'app' => $app,
                'company' => $company,
            ]
        );

        // via() is what Laravel calls before toMail(); it populates data['user'] (the store owner)
        $notification->via($user);

        $data = $notification->getData();

        $this->assertSame($order->getId(), $data['order']->getId());
        $this->assertSame($user->getId(), $data['admin']->getId());
        $this->assertSame($order->getId(), $data['entity']->getId());
        $this->assertSame($user->getId(), $data['user']->getId());

        $rendered = new RenderTemplateAction($app, $company)->execute(
            templateName: 'legacy-new-order-store-owner',
            templateParams: $data,
            templateContent: 'Hi {{ $admin->firstname }}, order {{ $order->order_number }} was placed.',
        );

        $this->assertStringContainsString((string) $user->firstname, $rendered);
        $this->assertStringContainsString((string) $order->order_number, $rendered);
    }
}
