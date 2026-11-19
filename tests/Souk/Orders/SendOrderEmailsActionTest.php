<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\OrderNotification;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Tests\TestCase;
use Tests\Traits\MakesFilesystemRows;

final class SendOrderEmailsActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesFilesystemRows;

    protected array $connectionsToTransact = [null, 'commerce', 'ecosystem'];

    public function testTheTemplateReceivesTheCompanyLogo(): void
    {
        Notification::fake();
        $company = $this->orderCompany();
        $logo = $this->makeFilesystemRow($company->getId(), 'logo.png');
        $company->addFile($logo, 'photo');

        new SendOrderEmailsAction($this->orderFor($company), 'owner-order-returned')->execute();

        Notification::assertSentOnDemand(
            OrderNotification::class,
            fn (OrderNotification $notification): bool => $notification->getData()['company_logo'] === $logo->url
        );
    }

    public function testACompanyWithoutALogoStillSendsTheEmail(): void
    {
        Notification::fake();
        $company = $this->orderCompany();

        new SendOrderEmailsAction($this->orderFor($company), 'owner-order-returned')->execute();

        Notification::assertSentOnDemand(
            OrderNotification::class,
            fn (OrderNotification $notification): bool => array_key_exists('company_logo', $notification->getData())
        );
    }

    public function testTheEmailUsesTheTemplateSavedForTheOrderCompany(): void
    {
        Notification::fake();
        $company = $this->orderCompany();
        new CreateTemplateAction(new TemplateInput(
            app: app(Apps::class),
            name: 'owner-order-returned',
            template: '<p>Returned order {{ order_id }} for {{ company_name }}</p>',
            company: $company,
        ))->execute();
        $order = $this->orderFor($company);

        new SendOrderEmailsAction($order, 'owner-order-returned')->execute();

        Notification::assertSentOnDemand(
            OrderNotification::class,
            fn (OrderNotification $notification): bool => str_contains(
                $notification->getEmailContent(),
                'Returned order ' . $order->getId() . ' for ' . e($company->name)
            )
        );
    }

    private function orderCompany(): Companies
    {
        return Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    private function orderFor(Companies $company): Order
    {
        return Order::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }
}
