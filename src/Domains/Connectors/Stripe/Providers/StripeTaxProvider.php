<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Providers;

use Baka\Contracts\AppInterface;
use Baka\Support\Money;
use Carbon\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\StripeTaxCodeMap;
use Kanvas\Connectors\Stripe\Services\StripeTaxService;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxComponent;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

class StripeTaxProvider implements TaxProviderInterface
{
    private const TAX_DATE_WINDOW_DAYS = 31;

    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly StripeTaxService $taxService,
        private readonly ?StripeClient $clientOverride = null,
    ) {
    }

    private function client(): StripeClient
    {
        return $this->clientOverride ?? $this->taxService->client($this->app, $this->company);
    }

    public function calculate(TaxContext $ctx): TaxResult
    {
        if (! $ctx->shipFrom || ! $ctx->shipFrom->country) {
            return TaxResult::failed('shipping_address_invalid');
        }

        $taxDate = $ctx->taxDate !== '' ? Carbon::parse($ctx->taxDate) : null;

        if ($taxDate !== null && Carbon::now()->diffInDays($taxDate) > self::TAX_DATE_WINDOW_DAYS) {
            return TaxResult::failed('tax_date_unsupported');
        }

        $taxCodeOverride = $this->extractTaxCodeOverride($ctx);

        if ($taxCodeOverride !== null && ! $this->isValidPtcCode($taxCodeOverride)) {
            return TaxResult::failed('invalid_tax_code_override');
        }

        try {
            $params = [
                'currency' => strtolower($ctx->currency),
                'line_items' => $this->buildLineItems($ctx, $taxCodeOverride),
                'ship_from_details' => [
                    'address' => $this->buildStripeAddress($ctx->shipFrom),
                ],
                'customer_details' => [
                    'address' => $this->buildStripeAddress($ctx->shipTo),
                    'address_source' => 'shipping',
                ],
            ];

            if ($taxDate !== null) {
                $params['tax_date'] = $taxDate->timestamp;
            }

            $calculation = $this->client()->tax->calculations->create($params);

            if (! empty($calculation->not_collecting) && ($calculation->tax_amount_exclusive ?? 0) === 0) {
                return TaxResult::unsupported('not_collecting');
            }

            return new TaxResult(
                treatment: TaxTreatmentEnum::TAXED,
                totalTax: Money::fromMinorUnits($calculation->tax_amount_exclusive ?? 0, $ctx->currency),
                totalTaxable: Money::fromMinorUnits($calculation->taxable_amount ?? 0, $ctx->currency),
                components: $this->buildComponents($calculation, $ctx),
                providerTransactionId: $calculation->id,
                metadata: [
                    'account_fingerprint' => $this->taxService->accountFingerprint($this->app, $this->company),
                    'calculation_id' => $calculation->id,
                    'currency' => $ctx->currency,
                ],
            );
        } catch (Throwable $e) {
            return TaxResult::failed($e->getMessage());
        }
    }

    public function commit(OrderTaxSnapshot $snapshot): void
    {
        $metadata = (array) ($snapshot->metadata ?? []);
        $calculationId = $metadata['calculation_id'] ?? null;

        if (! $calculationId) {
            return;
        }

        if (isset($metadata['transaction_id'])) {
            return;
        }

        $snapshotFingerprint = $metadata['account_fingerprint'] ?? null;
        $currentFingerprint = $this->taxService->accountFingerprint($this->app, $this->company);

        if ($snapshotFingerprint !== null && $snapshotFingerprint !== $currentFingerprint) {
            throw new RuntimeException('account_mismatch');
        }

        $idempotencyKey = hash('sha256', 'commit:' . $snapshot->uuid);

        $transaction = $this->client()->tax->transactions->createFromCalculation(
            ['calculation' => $calculationId, 'reference' => $snapshot->uuid],
            ['idempotency_key' => $idempotencyKey],
        );

        $snapshot->metadata = array_merge($metadata, ['transaction_id' => $transaction->id]);
        $snapshot->saveQuietly();
    }

    public function reverse(OrderTaxSnapshot $snapshot, TaxReversal $reversal): void
    {
        $metadata = (array) ($snapshot->metadata ?? []);
        $transactionId = $metadata['transaction_id'] ?? null;

        if (! $transactionId) {
            return;
        }

        $reversalRef = $reversal->refundUuid ?? hash('sha256', 'reversal:' . $snapshot->uuid . ':' . $reversal->amount);
        $alreadyReversedKey = 'reversal_id_' . $reversalRef;

        if (isset($metadata[$alreadyReversedKey])) {
            return;
        }

        $hasPriorReversal = collect(array_keys($metadata))
            ->contains(fn (string $key) => str_starts_with($key, 'reversal_id_'));

        $modeParams = $reversal->isFullReversal && ! $hasPriorReversal
            ? ['mode' => 'full']
            : [
                'mode' => 'partial',
                'flat_amount' => -Money::minorUnits($reversal->amount, $metadata['currency'] ?? 'usd'),
            ];

        $stripeReversal = $this->client()->tax->transactions->createReversal([
            'original_transaction' => $transactionId,
            'reference' => $reversalRef,
            ...$modeParams,
        ]);

        $snapshot->metadata = array_merge($metadata, [$alreadyReversedKey => $stripeReversal->id]);
        $snapshot->saveQuietly();
    }

    /**
     * A calculation that was never committed has nothing to undo on Stripe's side.
     */
    public function void(OrderTaxSnapshot $snapshot): void
    {
    }

    private function buildLineItems(TaxContext $ctx, ?string $globalTaxCodeOverride): array
    {
        return array_map(fn (TaxableLine $line): array => [
            'amount' => Money::minorUnits($line->subtotal(), $ctx->currency),
            'reference' => $line->lineId,
            'tax_code' => $this->taxCodeFor($line, $globalTaxCodeOverride),
        ], $ctx->lines);
    }

    private function taxCodeFor(TaxableLine $line, ?string $globalTaxCodeOverride): string
    {
        if (! $line->isTaxable) {
            return StripeTaxCodeMap::NON_TAXABLE;
        }

        return $line->taxCodeOverride
            ?? $globalTaxCodeOverride
            ?? ($line->taxCategory
                ? StripeTaxCodeMap::fromTaxCategory(TaxCategoryEnum::from($line->taxCategory))
                : StripeTaxCodeMap::GENERAL_TANGIBLE);
    }

    private function buildStripeAddress(TaxAddress $address): array
    {
        return array_filter([
            'country' => $address->country,
            'state' => $address->state,
            'postal_code' => $address->postalCode,
            'city' => $address->city,
        ]);
    }

    private function buildComponents(mixed $calculation, TaxContext $ctx): array
    {
        $components = [];
        $lineItems = $calculation->line_items ?? null;

        if ($lineItems === null) {
            return $components;
        }

        $iterator = method_exists($lineItems, 'autoPagingIterator')
            ? $lineItems->autoPagingIterator()
            : ($lineItems->data ?? []);

        foreach ($iterator as $lineItem) {
            $taxBreakdown = $lineItem->tax_breakdown ?? [];

            foreach ($taxBreakdown as $breakdown) {
                $taxAmountMinor = $breakdown->amount ?? 0;
                $taxableAmountMinor = $breakdown->taxable_amount ?? 0;

                $components[] = new TaxComponent(
                    lineId: $lineItem->reference ?? '',
                    taxType: $breakdown->tax_rate_details->type ?? 'sales_tax',
                    jurisdiction: $breakdown->jurisdiction->display_name ?? null,
                    rate: (string) (($breakdown->tax_rate_details->percentage_decimal ?? 0) / 100),
                    taxableAmount: Money::fromMinorUnits($taxableAmountMinor, $ctx->currency),
                    taxAmount: Money::fromMinorUnits($taxAmountMinor, $ctx->currency),
                    taxabilityReason: $breakdown->taxability_reason ?? 'taxable',
                    sourcing: $breakdown->sourcing ?? null,
                );
            }
        }

        return $components;
    }

    private function extractTaxCodeOverride(TaxContext $ctx): ?string
    {
        foreach ($ctx->lines as $line) {
            if ($line->taxCodeOverride !== null) {
                return $line->taxCodeOverride;
            }
        }

        return null;
    }

    private function isValidPtcCode(string $code): bool
    {
        return preg_match('/^txcd_\d{8}$/', $code) === 1;
    }
}
