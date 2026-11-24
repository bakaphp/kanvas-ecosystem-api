<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Providers\AIProviderInterface;

/**
 * What the lead-summary stubs answer with, settable from a test. One holder and not trait statics:
 * a trait's static is a separate variable in every class that uses it, and the customer and internal
 * stubs have to see the same providers.
 */
final class LeadSummaryStubProviders
{
    public static ?FakeNeuronProvider $chats = null;

    public static ?AIProviderInterface $summaries = null;

    public static int $window = 40;

    public static function reset(): void
    {
        self::$chats = new FakeNeuronProvider('ok');
        self::$summaries = null;
        self::$window = 40;
    }
}
