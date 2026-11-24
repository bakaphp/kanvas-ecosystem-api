<?php

declare(strict_types=1);

namespace Tests\Connectors\EchoPay;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Webhook\PullPaymentChallengeWebhookJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class PullPaymentChallengeReturnUrlTest extends TestCase
{
    public function testTheReturnUrlAnswersHtmlSoTheIframeRunsThePostMessage(): void
    {
        $order = $this->orderWithPayment();
        $receiver = $this->receiver();

        $response = $this->post('/v1/receiver/' . $receiver->uuid, [
            'MD' => $order->getId(),
            'TransactionId' => 'txn-3ds-123',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('<script>', $response->getContent());
        $this->assertStringContainsString('payment_complete', $response->getContent());
        $this->assertSame(
            'txn-3ds-123',
            $order->fresh()->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value)
        );
    }

    private function receiver(): ReceiverWebhook
    {
        $user = Auth::user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => PullPaymentChallengeWebhookJob::class],
            ['name' => 'PullPaymentChallengeWebhookJob']
        );

        return ReceiverWebhook::factory()
            ->app(app(Apps::class)->getId())
            ->user($user->getId())
            ->company($user->getCurrentCompany()->getId())
            ->create([
                'action_id' => $action->getId(),
                'run_async' => 0,
            ]);
    }

    private function orderWithPayment(): Order
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create();

        $order->payments()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'amount' => $order->getTotalAmount(),
            'payment_date' => date('Y-m-d'),
            'concept' => 'test',
            'currency' => 'DOP',
            'status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            'payment_method' => 'card',
        ]);

        return $order;
    }
}
