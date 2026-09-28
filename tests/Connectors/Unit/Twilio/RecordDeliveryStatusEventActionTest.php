<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Twilio;

use Kanvas\Connectors\Twilio\Actions\RecordDeliveryStatusEventAction;
use PHPUnit\Framework\TestCase;

final class RecordDeliveryStatusEventActionTest extends TestCase
{
    public function testAllowsForwardStatusProgression(): void
    {
        $this->assertTrue(RecordDeliveryStatusEventAction::canAdvanceStatus('queued', 'sent'));
        $this->assertTrue(RecordDeliveryStatusEventAction::canAdvanceStatus('sent', 'delivered'));
    }

    public function testDoesNotMoveTerminalStatusBackward(): void
    {
        $this->assertFalse(RecordDeliveryStatusEventAction::canAdvanceStatus('delivered', 'sent'));
        $this->assertFalse(RecordDeliveryStatusEventAction::canAdvanceStatus('delivered', 'failed'));
    }
}
