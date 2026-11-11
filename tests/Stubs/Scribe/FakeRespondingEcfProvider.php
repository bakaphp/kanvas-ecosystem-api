<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\RespondsToReceivedEcfInterface;

/**
 * FakeEcfProvider that can also answer a vendor e-CF; records each commercial response it was asked to send.
 */
class FakeRespondingEcfProvider extends FakeEcfProvider implements RespondsToReceivedEcfInterface
{
    /** @var list<array{bill_id: int, accepted: bool, reason: string|null}> */
    public static array $responses = [];

    public static function reset(): void
    {
        parent::reset();
        self::$responses = [];
    }

    public function sendCommercialResponse(Bill $bill, bool $accepted, ?string $reason = null): ?string
    {
        self::$responses[] = ['bill_id' => $bill->getId(), 'accepted' => $accepted, 'reason' => $reason];

        return 'fake-response-' . count(self::$responses);
    }
}
