<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\SalesAssist;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCaseUnit;

final class AgentMessageNoteChannelLabelTest extends TestCaseUnit
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function labelProvider(): array
    {
        return [
            'sms follow-up filed under the email session channel' => ['twilio-sms', 'email-john-at-example-dot-com', '(Sms) Sales Assist: hi'],
            'email verb on an email channel' => ['email', 'email-john-at-example-dot-com', '(Email) Sales Assist: hi'],
            'mailgun verb' => ['mailgun-email', 'twilio-18095551234', '(Email) Sales Assist: hi'],
            'whatsapp verb on the lead conversation channel' => ['whatsapp-text', 'lead-channel-1', '(Whatsapp) Sales Assist: hi'],
            'unknown verb falls back to the channel slug' => ['prompt', 'twilio-18095551234', '(Sms) Sales Assist: hi'],
            'unknown verb and no channel defaults to sms' => ['prompt', null, '(Sms) Sales Assist: hi'],
            'note verb is an agent note regardless of channel' => ['note', 'email-john-at-example-dot-com', 'Agent Note: hi'],
        ];
    }

    #[DataProvider('labelProvider')]
    public function testLabelComesFromTheMessageVerbBeforeTheChannelSlug(string $verb, ?string $channelSlug, string $expected): void
    {
        $message = new Message();
        $message->setRelation('messageType', new MessageType(['verb' => $verb]));
        $message->setRelation('user', new Users(['firstname' => 'Sales', 'lastname' => 'Assist']));
        $message->setRelation(
            'channels',
            new Collection($channelSlug === null ? [] : [new Channel(['slug' => $channelSlug])]),
        );

        $this->assertSame($expected, $this->formatNote($message, 'hi', true));
    }

    public function testCustomerMessageCarriesNoChannelLabel(): void
    {
        $message = new Message();
        $message->setRelation('messageType', new MessageType(['verb' => 'twilio-sms']));
        $message->setRelation('channels', new Collection());

        $this->assertSame('Customer: hi', $this->formatNote($message, 'hi', false));
    }

    public function testFallsBackToSallyWhenTheSenderHasNoName(): void
    {
        $message = new Message();
        $message->setRelation('messageType', new MessageType(['verb' => 'twilio-sms']));
        $message->setRelation('user', new Users());
        $message->setRelation('channels', new Collection());

        $this->assertSame('(Sms) Sally: hi', $this->formatNote($message, 'hi', true));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function verbProvider(): array
    {
        return [
            'twilio sms' => ['twilio-sms', ChannelCategoryEnum::SMS->value],
            'bare sms' => ['sms', ChannelCategoryEnum::SMS->value],
            'mailgun email' => ['mailgun-email', ChannelCategoryEnum::EMAIL->value],
            'bare email' => ['email', ChannelCategoryEnum::EMAIL->value],
            'whatsapp text' => ['whatsapp-text', ChannelCategoryEnum::WHATSAPP->value],
            'respondio image' => ['respondio-image', ChannelCategoryEnum::RESPONDIO->value],
            'note' => ['note', null],
            'system notes' => ['system_notes', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('verbProvider')]
    public function testGetLeadChannelNameFromVerb(?string $verb, ?string $expected): void
    {
        $this->assertSame($expected, ChannelCategoryEnum::getLeadChannelNameFromVerb($verb));
    }

    private function formatNote(Message $message, string $note, bool $fromAgent): string
    {
        $activity = $this->activity();

        return new ReflectionMethod($activity, 'buildFormattedNote')->invoke($activity, $message, $note, $fromAgent);
    }

    private function activity(): BaseAddLeadCommentFromAgentMessageActivity
    {
        return new class () extends BaseAddLeadCommentFromAgentMessageActivity {
            public function __construct()
            {
            }

            protected function getIntegration(): IntegrationsEnum
            {
                return IntegrationsEnum::INTERNAL;
            }

            protected function validateCompanyIntegration(Message $message): ?array
            {
                return null;
            }

            protected function addNoteToExternalSystem(
                Lead $lead,
                string $note,
                Message $message,
                Apps $app
            ): mixed {
                return $note;
            }
        };
    }
}
