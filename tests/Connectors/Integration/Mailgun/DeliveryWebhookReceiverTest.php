<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Services\DeliveryWebhookReceiverService;
use Kanvas\Connectors\Mailgun\Webhooks\EmailDeliveryWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

/**
 * Mailgun keeps one webhook URL per sending domain, so the delivery receiver is one per app and domain.
 */
final class DeliveryWebhookReceiverTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    private string $domain;

    /** @var array<int, string> */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mailgun.secret' => 'test-key']);
        $this->domain = 'mg-' . Str::lower(Str::random(10)) . '.example.test';

        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response(['message' => 'Webhook not found'], 404);
            }

            $this->registered[] = $request->url();

            return Http::response(['webhook' => ['urls' => []]], 200);
        });
    }

    public function testTheFirstSetupCreatesOneReceiverForTheDomainAndPointsEveryEventAtIt(): void
    {
        $service = new DeliveryWebhookReceiverService();
        $receiver = $service->ensure(app(Apps::class), $this->company(), $this->domain);

        $this->assertSame($this->domain, $receiver->configuration['mailgun']['domain']);
        $this->assertNotEmpty($receiver->configuration['mailgun']['registered_at']);
        $this->assertSame(EmailDeliveryWebhookJob::class, $receiver->action->model_name);
        $this->assertCount(count(DeliveryWebhookReceiverService::EVENTS), $this->registered);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v3/domains/' . $this->domain . '/webhooks')
            && str_contains((string) $request->body(), $receiver->getUrl()));
    }

    public function testAnotherCompanyOnTheSameDomainSharesTheReceiver(): void
    {
        $service = new DeliveryWebhookReceiverService();
        $first = $service->ensure(app(Apps::class), $this->company(), $this->domain);

        $other = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $second = $service->ensure(app(Apps::class), $other, $this->domain);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(1, ReceiverWebhook::query()->where('configuration->mailgun->domain', $this->domain)->count());
    }

    public function testAReceiverSwitchedOffIsLeftOff(): void
    {
        $service = new DeliveryWebhookReceiverService();
        $receiver = $service->ensure(app(Apps::class), $this->company(), $this->domain);
        $receiver->forceFill(['is_active' => false])->saveOrFail();
        $this->registered = [];

        $service->ensure(app(Apps::class), $this->company(), $this->domain);

        $this->assertSame([], $this->registered);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
