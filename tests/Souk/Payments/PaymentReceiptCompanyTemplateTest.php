<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Notifications\PaymentReceiptNotification;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Tests\TestCase;

final class PaymentReceiptCompanyTemplateTest extends TestCase
{
    use DatabaseTransactions;

    public function testAReceiptRendersATemplateThatOnlyExistsForTheOrderCompany(): void
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $templateName = 'payment_receipt_company_only_' . uniqid();

        new CreateTemplateAction(new TemplateInput(
            app: $app,
            name: $templateName,
            template: '<p>Receipt {{ receipt.order_number }}</p>',
            company: $company,
        ))->execute();

        $order = new Order();
        $order->user_email = 'receipt@example.com';
        $order->user_phone = '8095550000';
        $order->setRelation('items', collect());
        $order->setRelation('app', $app);
        $order->setRelation('company', $company);

        $notification = new PaymentReceiptNotification(
            $order,
            new Payments(),
            ['receipt' => ['order_number' => 'ORD-77']],
        );

        $this->assertStringContainsString(
            'Receipt ORD-77',
            $notification->setDatabaseTemplateName($templateName)->message()
        );
    }
}
