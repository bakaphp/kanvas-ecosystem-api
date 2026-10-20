<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\ResendFailedMessageAction;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class ResendFailedMessageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    public function testResendsTheSameTextToTheNumberOfTheFailedAttempt(): void
    {
        [$message] = $this->failedMessage();
        $action = $this->makeAction($message, [
            'channel' => 'sms',
            'messages' => [['sid' => 'SM-resent', 'status' => 'queued', 'to' => '+18095551234']],
        ]);

        $action->execute();

        $this->assertSame('sms', $action->sent['channel']);
        $this->assertSame('Your appointment is at 3pm', $action->sent['message']);
        $this->assertSame('+18095551234', $action->sent['to']);
        $this->assertSame('+18095550000', $action->sent['from']);
        $this->assertNull($action->sent['files']);

        $message = $message->fresh();
        $this->assertSame('SM-resent', $message->get(CustomFieldEnum::MESSAGE_SID->value));
        $this->assertSame('queued', $message->get(CustomFieldEnum::CURRENT_STATUS->value));
        $delivery = $message->getMessage()['delivery'];
        $this->assertSame('queued', $delivery['status']);
        $this->assertSame('SM-resent', $delivery['message_sid']);
        $this->assertNull($delivery['error_code']);
    }

    public function testAResendTwilioRejectsIsRecordedAsFailedWithItsCode(): void
    {
        [$message] = $this->failedMessage();
        $action = $this->makeAction($message, [
            'status' => 'error',
            'twilio_error_code' => 21211,
            'error' => 'Invalid To number',
            'messages' => [],
        ]);

        $action->execute();

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame('21211', $delivery['error_code']);
        $this->assertSame('Invalid To number', $delivery['error_message']);
    }

    public function testRefusesAMessageThatDidNotFail(): void
    {
        [$message] = $this->failedMessage();
        $message->set(CustomFieldEnum::CURRENT_STATUS->value, 'delivered');
        $action = $this->makeAction($message, []);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('this one is delivered');

        try {
            $action->execute();
        } finally {
            $this->assertNull($action->sent);
        }
    }

    public function testRefusesAMessageThatIsNotATwilioSend(): void
    {
        [$message] = $this->failedMessage();
        $message->del(CustomFieldEnum::CURRENT_STATUS->value);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('this one is not a Twilio message');

        $this->makeAction($message, [])->execute();
    }

    public function testRefusesAMessageWithNoLead(): void
    {
        $message = $this->createMessage();
        $message->set(CustomFieldEnum::CURRENT_STATUS->value, 'failed');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not linked to a lead');

        $this->makeAction($message, [])->execute();
    }

    public function testASecondResendWhileTheFirstIsRunningIsRefused(): void
    {
        [$message] = $this->failedMessage();
        $lock = Cache::lock('twilio:resend:' . $message->getId(), 60);
        $lock->get();
        $action = $this->makeAction($message, []);

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('already being resent');

            $action->execute();
        } finally {
            $lock->release();
            $this->assertNull($action->sent);
        }
    }

    public function testTheRealSenderIsTheLeadAction(): void
    {
        [$message, $lead] = $this->failedMessage();

        $action = new class ($message) extends ResendFailedMessageAction {
            public function senderForTest(Lead $lead): SendMessageToLeadAction
            {
                return $this->sender($lead);
            }
        };

        $this->assertInstanceOf(SendMessageToLeadAction::class, $action->senderForTest($lead));
    }

    public function testMutationRefusesAMessageThatDidNotFail(): void
    {
        [$message] = $this->failedMessage();
        $message->set(CustomFieldEnum::CURRENT_STATUS->value, 'delivered');

        $response = $this->graphQL('
            mutation ($id: ID!) {
                twilioResendMessage(message_id: $id) { id }
            }
        ', ['id' => $message->getId()]);

        $this->assertStringContainsString(
            'Only a failed or undelivered Twilio message can be resent',
            (string) $response->json('errors.0.message'),
        );
    }

    /** @return array{0: Message, 1: Lead} */
    private function failedMessage(): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $message = $this->createMessage();
        $message->addEntity($lead);
        new StoreMessageSidAction($message)->execute([
            'status' => 'error',
            'twilio_error_code' => 30003,
            'error' => 'Unreachable destination handset',
            'from' => '+18095550000',
            'to' => '+18095551234',
            'messages' => [],
        ]);

        return [$message->fresh(), $lead];
    }

    private function createMessage(): Message
    {
        $user = auth()->user();

        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'users_id' => $user->getId(),
                'message' => ['content' => 'Your appointment is at 3pm'],
            ]);
    }

    private function makeAction(Message $message, array $providerResponse): ResendFailedMessageAction
    {
        return new class ($message, $providerResponse) extends ResendFailedMessageAction {
            public ?array $sent = null;

            public function __construct(Message $message, private readonly array $providerResponse)
            {
                parent::__construct($message);
            }

            protected function sender(Lead $lead): SendMessageToLeadAction
            {
                $test = $this;

                return new class ($lead, $test, $this->providerResponse) extends SendMessageToLeadAction {
                    public function __construct(
                        Lead $lead,
                        private readonly object $test,
                        private readonly array $providerResponse,
                    ) {
                        parent::__construct($lead);
                    }

                    public function execute(
                        string $channel,
                        string $message,
                        ?string $from = '',
                        ?string $title = null,
                        bool $signature = true,
                        ?Collection $files = null,
                        ?string $to = null,
                        ?array $cc = null,
                        ?Agent $fromAgent = null
                    ): array {
                        $this->test->sent = [
                            'channel' => $channel,
                            'message' => $message,
                            'from' => $from,
                            'to' => $to,
                            'files' => $files,
                        ];

                        return $this->providerResponse;
                    }
                };
            }
        };
    }
}
