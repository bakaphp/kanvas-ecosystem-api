<?php

declare(strict_types=1);

namespace Tests\Communication\Webhooks;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeInbound;
use Tests\TestCase;

/**
 * One webhook job for every provider: the receiver names the provider, the provider proves the
 * request and says what it carries.
 */
final class ProcessCommunicationWebhookJobTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private FakeInbound $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->vendor = $this->useFakeInbound();

        LeadType::firstOrCreate(
            ['apps_id' => $this->currentApp()->getId(), 'companies_id' => $this->currentCompany()->getId(), 'name' => 'Warm'],
            ['description' => 'Warm Lead Type', 'is_active' => true, 'uuid' => Str::uuid()]
        );
    }

    public function testSetupGivesEachProviderOneReceiverOnTheSharedJob(): void
    {
        $receivers = app(CommunicationReceivers::class);

        $first = $receivers->ensure($this->currentApp(), $this->currentCompany(), 'fake');
        $again = $receivers->ensure($this->currentApp(), $this->currentCompany(), 'fake');

        $this->assertSame($first->getId(), $again->getId());
        $this->assertSame(ProcessCommunicationWebhookJob::class, $first->action->model_name);
        $this->assertSame('fake', $first->configuration['communication']['provider']);
    }

    public function testOnlyTheReceiversProviderCanSignForIt(): void
    {
        $receiver = $this->receiver('fake');

        $this->assertTrue(ProcessCommunicationWebhookJob::authenticateRequest($this->request($receiver, signed: true), $receiver));
        $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest($this->request($receiver, signed: false), $receiver));
    }

    /**
     * A receiver naming no provider, or one nobody registered, cannot be authenticated by anyone.
     */
    public function testAReceiverWithoutAKnownProviderAcceptsNothing(): void
    {
        foreach ([$this->receiver(null), $this->receiver('nobody')] as $receiver) {
            $this->assertFalse(ProcessCommunicationWebhookJob::authenticateRequest($this->request($receiver, signed: true), $receiver));
        }
    }

    /**
     * Meta sends messages and statuses in one payload; both are handled in one run.
     */
    public function testMessagesAndStatusesInOnePayload(): void
    {
        $receiver = $this->receiver('fake');

        $result = $this->deliver($receiver, [
            'messages' => [
                ['id' => 'wamid.10', 'from' => '+18095550710', 'to' => '+18095550799', 'text' => 'Hola'],
                ['id' => 'wamid.11', 'from' => '+18095550711', 'to' => '+18095550799', 'text' => 'Buenas'],
            ],
            'statuses' => [['id' => 'wamid.out.1', 'status' => 'delivered']],
        ]);

        $this->assertSame(['recorded' => 1], $result['statuses']);
        $this->assertSame('Hola', Message::query()->findOrFail($result[0]['message_id'])->message['content']);
        $this->assertSame('Buenas', Message::query()->findOrFail($result[1]['message_id'])->message['content']);
        $this->assertCount(1, $this->vendor->statuses);
    }

    public function testAStatusOnlyPayloadFilesNoMessage(): void
    {
        $result = $this->deliver($this->receiver('fake'), ['statuses' => [['id' => 'wamid.out.2', 'status' => 'read']]]);

        $this->assertSame(['recorded' => 1], $result);
    }

    private function receiver(?string $provider): ReceiverWebhook
    {
        $receiver = app(CommunicationReceivers::class)->ensure($this->currentApp(), $this->currentCompany(), 'fake');

        if ($provider === 'fake') {
            return $receiver;
        }

        $other = $receiver->replicate(['uuid']);
        $other->configuration = $provider === null ? [] : ['communication' => ['provider' => $provider]];
        $other->saveOrFail();

        return $other;
    }

    private function request(ReceiverWebhook $receiver, bool $signed, array $payload = []): Request
    {
        $request = Request::create($receiver->getUrl(), 'POST', $payload);

        if ($signed) {
            $request->headers->set('X-Fake-Signature', FakeInbound::SIGNATURE);
        }

        return $request;
    }

    private function deliver(ReceiverWebhook $receiver, array $payload): array
    {
        $call = new ProcessWebhookAttemptAction($receiver, $this->request($receiver, signed: true, payload: $payload))->execute();

        return new ProcessCommunicationWebhookJob($call)->handle();
    }
}
