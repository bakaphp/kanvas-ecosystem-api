<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Reynolds;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Connectors\Reynolds\Activities\PushLeadNotesActivity;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCaseUnit;

/**
 * A conversation message has no engagement card, so Reynolds files its content. It has to read the
 * way it does in VinSolutions: who said it, and over which channel.
 */
final class PushLeadNotesConversationLabelTest extends TestCaseUnit
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function noteProvider(): array
    {
        return [
            'sms we sent' => ['twilio-sms', ['content' => ' hi ', 'from_me' => true], '(Sms) Sales Assist: hi'],
            'email we sent' => ['mailgun-email', ['content' => 'hi', 'from_me' => true], '(Email) Sales Assist: hi'],
            'sms from the customer' => ['twilio-sms', ['content' => 'hi', 'from_me' => false], 'Customer: hi'],
            'no direction flag reads as the customer' => ['twilio-sms', ['content' => 'hi'], 'Customer: hi'],
            'internal note' => ['note', ['content' => 'hi', 'from_me' => true], 'Agent Note: hi'],
            'nothing to say stays empty' => ['twilio-sms', ['content' => '  ', 'from_me' => true], ''],
            'non-text content stays empty' => ['twilio-sms', ['content' => ['a' => 'b'], 'from_me' => true], ''],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('noteProvider')]
    public function testConversationMessageIsLabelledLikeTheOtherDealerCrms(
        string $verb,
        array $payload,
        string $expected
    ): void {
        $message = new Message();
        $message->message = $payload;
        $message->setRelation('messageType', new MessageType(['verb' => $verb]));
        $message->setRelation('user', new Users(['firstname' => 'Sales', 'lastname' => 'Assist']));
        $message->setRelation('channels', new Collection());

        $activity = new class () extends PushLeadNotesActivity {
            public function __construct()
            {
            }
        };

        $this->assertSame(
            $expected,
            new ReflectionMethod(PushLeadNotesActivity::class, 'buildConversationNote')->invoke($activity, $message),
        );
    }
}
