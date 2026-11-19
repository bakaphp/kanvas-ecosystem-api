<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CardNet\Ztrans\Services\RedirectReceiverService;
use Kanvas\Connectors\CardNet\Ztrans\Webhook\CardNetZtransRedirectWebhookJob;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class RedirectReceiverServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    public function testEnsureCreatesOneSynchronousReceiverAndReusesIt(): void
    {
        $this->registerAction();
        $service = new RedirectReceiverService();

        $first = $service->ensure($this->kanvasApp, $this->company);
        $second = $service->ensure($this->kanvasApp, $this->company);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertFalse($first->runAsync());
        $this->assertSame($this->company->getId(), $first->companies_id);
        $this->assertSame(
            1,
            ReceiverWebhook::query()
                ->fromApp($this->kanvasApp)
                ->fromCompany($this->company)
                ->forJob(CardNetZtransRedirectWebhookJob::class)
                ->count()
        );
    }

    public function testRedirectUrlPointsToTheReceiverWithThePaymentId(): void
    {
        $this->registerAction();
        $order = new Order();
        $order->setRelation('app', $this->kanvasApp);
        $order->setRelation('company', $this->company);
        $payment = new Payments();
        $payment->id = 77;

        $url = new RedirectReceiverService()->redirectUrl($order, $payment);

        $receiver = new RedirectReceiverService()->ensure($this->kanvasApp, $this->company);
        $this->assertSame($receiver->getUrl() . '?payment_id=77', $url);
    }

    private function registerAction(): void
    {
        WorkflowAction::query()->firstOrCreate(
            ['model_name' => CardNetZtransRedirectWebhookJob::class],
            ['name' => 'CardNet Ztrans 3DS Challenge Redirect']
        );
    }
}
