<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Services\MercuryCardService;
use Kanvas\Scribe\Banking\Models\BankAccount;
use RuntimeException;

/**
 * Stores the cards attached to a Mercury account on the bank account's metadata.
 *
 * Cards are reference data, not accounting entities — they don't get their own table until something needs
 * to query across them. What they buy us today is attribution: a card transaction's `cardId` can be resolved
 * to a holder, which is a strong signal for the matcher and for expense classification.
 */
class PullMercuryCardsAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly BankAccount $bankAccount,
        protected readonly ?MercuryCardService $cardService = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $mercuryAccountId = $this->bankAccount->external_id;

        if ($mercuryAccountId === null || $this->bankAccount->source !== 'mercury') {
            throw new RuntimeException(
                "BankAccount {$this->bankAccount->getId()} is not a Mercury account — refusing to sync cards."
            );
        }

        $service = $this->cardService ?? new MercuryCardService($this->app, $this->company);
        $cards = $service->listForAccount($mercuryAccountId);

        $metadata = $this->bankAccount->metadata ?? [];
        $metadata['cards'] = $cards;
        $metadata['cards_synced_at'] = Carbon::now()->toIso8601String();

        $this->bankAccount->metadata = $metadata;
        $this->bankAccount->save();

        return $cards;
    }
}
