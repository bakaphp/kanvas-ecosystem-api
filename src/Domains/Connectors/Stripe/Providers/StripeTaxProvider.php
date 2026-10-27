<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Providers;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\StripeTaxCodeMap;
use Kanvas\Connectors\Stripe\Services\StripeTaxService;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxComponent;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxCoverage;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Exceptions\IllegalTaxTransition;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

class StripeTaxProvider implements TaxProviderInterface
{
    private const EU_COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];
    private const SUPPORTED_NON_EU = ['US', 'CA', 'MX', 'GB'];
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

        if ($ctx->taxDate !== '') {
            $taxDate = Carbon::parse($ctx->taxDate);
            $diffDays = abs(Carbon::now()->diffInDays($taxDate, absolute: false));

            if ($diffDays > self::TAX_DATE_WINDOW_DAYS) {
                return TaxResult::failed('tax_date_unsupported');
            }
        }

        $taxCodeOverride = $this->extractTaxCodeOverride($ctx);

        if ($taxCodeOverride !== null) {
            if (! $this->isValidPtcCode($taxCodeOverride)) {
                return TaxResult::failed('invalid_tax_code_override');
            }
        }

        try {
            $lineItems = $this->buildLineItems($ctx, $taxCodeOverride);

            $params = [
                'currency' => strtolower($ctx->currency),
                'line_items' => $lineItems,
                'ship_from_details' => [
                    'address' => $this->buildStripeAddress($ctx->shipFrom->country, $ctx->shipFrom->state, $ctx->shipFrom->postalCode, $ctx->shipFrom->city),
                ],
                'customer_details' => [
                    'address' => $this->buildStripeAddress($ctx->shipTo->country, $ctx->shipTo->state, $ctx->shipTo->postalCode, $ctx->shipTo->city),
                    'address_source' => 'shipping',
                ],
            ];

            if ($ctx->taxDate !== '') {
                $params['tax_date'] = Carbon::parse($ctx->taxDate)->timestamp;
            }

            $calculation = $this->client()->tax->calculations->create($params);

            if (($calculation->tax_amount_exclusive ?? 0) === 0 && isset($calculation->not_collecting) && $calculation->not_collecting) {
                return TaxResult::unsupported('not_collecting');
            }

            $fingerprint = $this->taxService->accountFingerprint($this->app, $this->company);
            $components = $this->buildComponents($calculation, $ctx);

            $totalTaxMinor = $calculation->tax_amount_exclusive ?? 0;
            $totalTaxableMinor = $calculation->taxable_amount ?? 0;

            return new TaxResult(
                treatment: TaxTreatmentEnum::TAXED,
                totalTax: $this->fromMinorUnits($totalTaxMinor, $ctx->currency),
                totalTaxable: $this->fromMinorUnits($totalTaxableMinor, $ctx->currency),
                components: $components,
                providerTransactionId: $calculation->id,
                metadata: ['account_fingerprint' => $fingerprint, 'calculation_id' => $calculation->id],
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

        $flatAmountMinor = $this->toMinorUnits((string) $reversal->taxAmount, $metadata['currency'] ?? 'usd');

        $reversal = $this->client()->tax->transactions->createReversal([
            'original_transaction' => $transactionId,
            'reference' => $reversalRef,
            'mode' => 'full',
            'flat_amount' => $flatAmountMinor,
        ]);

        $snapshot->metadata = array_merge($metadata, [$alreadyReversedKey => $reversal->id]);
        $snapshot->saveQuietly();
    }

    public function void(OrderTaxSnapshot $snapshot): void
    {
        try {
            $snapshot->transition('pending', 'voided');
        } catch (IllegalTaxTransition) {
        }
    }

    public function coverage(string $country, ?string $state = null): TaxCoverage
    {
        $countryUpper = strtoupper($country);
        $isSupported = in_array($countryUpper, self::SUPPORTED_NON_EU, true)
            || in_array($countryUpper, self::EU_COUNTRIES, true);

        return new TaxCoverage(
            country: $country,
            state: $state,
            provider: TaxProviderEnum::STRIPE_TAX->value,
            isSupported: $isSupported,
            unsupportedReason: $isSupported ? null : 'country_not_supported',
        );
    }

    private function buildLineItems(TaxContext $ctx, ?string $globalTaxCodeOverride): array
    {
        return array_map(function (TaxableLine $line) use ($globalTaxCodeOverride): array {
            if (! $line->isTaxable) {
                return [
                    'amount' => $this->toMinorUnits($line->subtotal(), $line->reference),
                    'reference' => $line->lineId,
                    'tax_code' => 'txcd_00000000',
                ];
            }

            $taxCode = $line->taxCodeOverride
                ?? $globalTaxCodeOverride
                ?? ($line->taxCategory
                    ? StripeTaxCodeMap::fromTaxCategory(TaxCategoryEnum::from($line->taxCategory))
                    : StripeTaxCodeMap::GENERAL_TANGIBLE);

            return [
                'amount' => $this->toMinorUnits($line->subtotal(), $line->reference),
                'reference' => $line->lineId,
                'tax_code' => $taxCode,
            ];
        }, $ctx->lines);
    }

    private function buildStripeAddress(
        ?string $country,
        ?string $state,
        ?string $postalCode,
        ?string $city,
    ): array {
        return array_filter([
            'country' => $country,
            'state' => $state,
            'postal_code' => $postalCode,
            'city' => $city,
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
                    taxableAmount: $this->fromMinorUnits($taxableAmountMinor, $ctx->currency),
                    taxAmount: $this->fromMinorUnits($taxAmountMinor, $ctx->currency),
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

    private function toMinorUnits(string $amount, string $currencyHint = 'usd'): int
    {
        $currency = strtolower($currencyHint);
        $zeroDecimalCurrencies = ['jpy', 'krw', 'bif', 'clp', 'gnf', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'xaf', 'xof'];

        if (in_array($currency, $zeroDecimalCurrencies, true)) {
            return (int) round((float) $amount);
        }

        return (int) round((float) $amount * 100);
    }

    private function fromMinorUnits(int $minor, string $currencyHint = 'usd'): string
    {
        $currency = strtolower($currencyHint);
        $zeroDecimalCurrencies = ['jpy', 'krw', 'bif', 'clp', 'gnf', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'xaf', 'xof'];

        if (in_array($currency, $zeroDecimalCurrencies, true)) {
            return (string) $minor;
        }

        return number_format($minor / 100, 2, '.', '');
    }
}
