<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Kanvas\Connectors\Twilio\Enums\MessageStatusEnum;
use Tests\TestCaseUnit;

final class MessageStatusEnumTest extends TestCaseUnit
{
    public function testFromTwilioNormalizesCaseAndWhitespace(): void
    {
        $this->assertSame(MessageStatusEnum::DELIVERED, MessageStatusEnum::fromTwilio(' Delivered '));
        $this->assertNull(MessageStatusEnum::fromTwilio('not-a-status'));
        $this->assertNull(MessageStatusEnum::fromTwilio(null));
    }

    public function testOnlyDeliveredUndeliveredAndFailedAreDeliveryOutcomes(): void
    {
        $outcomes = array_values(array_filter(
            MessageStatusEnum::cases(),
            static fn (MessageStatusEnum $status): bool => $status->isDeliveryOutcome(),
        ));

        $this->assertSame(
            [MessageStatusEnum::DELIVERED, MessageStatusEnum::UNDELIVERED, MessageStatusEnum::FAILED],
            $outcomes,
        );
    }

    public function testCanceledIsTerminalButNotADeliveryOutcome(): void
    {
        $this->assertTrue(MessageStatusEnum::CANCELED->isTerminal());
        $this->assertFalse(MessageStatusEnum::CANCELED->isDeliveryOutcome());
        $this->assertFalse(MessageStatusEnum::SENT->isTerminal());
    }
}
