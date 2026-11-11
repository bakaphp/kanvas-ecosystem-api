<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\DataTransferObject;

/**
 * Where a country's official exchange rate comes from: the central bank that publishes it (the provider's bank
 * code, e.g. `bcrd`), which of its rates the tax authority wants (DGII: `buy`; a bank that publishes one fixing
 * uses its own type), and the local currency the rate is quoted in. Declared by each fiscal regime.
 */
final class OfficialRateSource
{
    public function __construct(
        public readonly string $bank,
        public readonly string $type,
        public readonly string $localCurrency,
    ) {
    }

    /**
     * Stored in `fx_rates.source`, so a rate always says which bank published it.
     */
    public function sourceLabel(): string
    {
        return 'central_bank:' . $this->bank;
    }
}
