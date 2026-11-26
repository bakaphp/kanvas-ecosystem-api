<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesOrders\Enums\PoSourceEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder as SalesOrderModel;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class SalesOrder extends Data
{
    /**
     * @param list<SalesOrderLine> $lines
     * @param array<string, bool>|null $signature_checklist
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $user,
        public readonly Organization $customer,
        public readonly PoSourceEnum $po_source,
        public readonly string $currency,
        public readonly array $lines,
        public readonly ?Quote $quote = null,
        public readonly ?string $customer_po_number = null,
        public readonly ?Carbon $customer_po_date = null,
        public readonly ?array $signature_checklist = null,
        public readonly ?string $incoterm = null,
        public readonly ?string $consignee = null,
        public readonly ?int $payment_terms_days = null,
        public readonly ?Carbon $requested_date = null,
        public readonly ?string $notes = null,
    ) {
    }

    /**
     * A signed proforma is the quote itself, so its lines, currency and number default to the quote's.
     *
     * @param array<string, mixed> $data
     */
    public static function fromMultiple(
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $data,
    ): self {
        $customer = Organization::getByIdFromCompanyApp((int) $data['customer_id'], $company, $app);
        $quote = isset($data['quote_id'])
            ? Quote::getByIdFromCompanyApp((int) $data['quote_id'], $company, $app)
            : null;
        $source = PoSourceEnum::from((string) $data['po_source']);
        $signedProforma = $source === PoSourceEnum::SIGNED_PROFORMA;
        $currency = $data['currency'] ?? $quote?->currency
            ?? throw new ValidationException('A sales order needs a currency.');

        return new self(
            app: $app,
            company: $company,
            user: $user,
            customer: $customer,
            po_source: $source,
            currency: strtoupper((string) $currency),
            lines: isset($data['lines']) ? self::linesFrom($data['lines']) : self::linesFromQuote($quote),
            quote: $quote,
            customer_po_number: Str::trimToNull($data['customer_po_number'] ?? null)
                ?? ($signedProforma ? $quote?->quote_number : null),
            customer_po_date: self::dateFrom($data['customer_po_date'] ?? null),
            signature_checklist: $data['signature_checklist'] ?? null,
            incoterm: Str::trimToNull($data['incoterm'] ?? null),
            consignee: Str::trimToNull($data['consignee'] ?? null),
            payment_terms_days: self::intFrom($data['payment_terms_days'] ?? null),
            requested_date: self::dateFrom($data['requested_date'] ?? null),
            notes: Str::trimToNull($data['notes'] ?? null),
        );
    }

    /**
     * The order's current values with the given changes laid over them, so a partial update keeps the rest. A
     * null sent for an optional field clears it; the PO number, its date and the checklist can only be replaced.
     *
     * @param array<string, mixed> $changes
     */
    public static function forUpdate(
        SalesOrderModel $order,
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $changes,
    ): self {
        return new self(
            app: $app,
            company: $company,
            user: $user,
            customer: $order->customer,
            po_source: $order->po_source,
            currency: strtoupper((string) ($changes['currency'] ?? $order->currency)),
            lines: isset($changes['lines'])
                ? self::linesFrom($changes['lines'])
                : $order->lines->map(static fn ($line): SalesOrderLine => new SalesOrderLine(
                    description: (string) $line->description,
                    quantity: (float) $line->quantity,
                    unit_price: (float) $line->unit_price,
                    sku: $line->sku,
                ))->all(),
            quote: $order->quote,
            customer_po_number: Str::trimToNull($changes['customer_po_number'] ?? null) ?? $order->customer_po_number,
            customer_po_date: self::dateFrom($changes['customer_po_date'] ?? null) ?? $order->customer_po_date,
            signature_checklist: $changes['signature_checklist'] ?? $order->signature_checklist,
            incoterm: array_key_exists('incoterm', $changes)
                ? Str::trimToNull($changes['incoterm'])
                : $order->incoterm,
            consignee: array_key_exists('consignee', $changes)
                ? Str::trimToNull($changes['consignee'])
                : $order->consignee,
            payment_terms_days: array_key_exists('payment_terms_days', $changes)
                ? self::intFrom($changes['payment_terms_days'])
                : $order->payment_terms_days,
            requested_date: array_key_exists('requested_date', $changes)
                ? self::dateFrom($changes['requested_date'])
                : $order->requested_date,
            notes: array_key_exists('notes', $changes) ? Str::trimToNull($changes['notes']) : $order->notes,
        );
    }

    private static function dateFrom(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value);
    }

    private static function intFrom(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return list<SalesOrderLine>
     */
    private static function linesFrom(array $lines): array
    {
        if ($lines === []) {
            throw new ValidationException('A sales order needs at least one line.');
        }

        return array_map(
            static fn (array $line): SalesOrderLine => new SalesOrderLine(
                description: (string) $line['description'],
                quantity: (float) $line['quantity'],
                unit_price: (float) $line['unit_price'],
                sku: Str::trimToNull($line['sku'] ?? null),
            ),
            array_values($lines)
        );
    }

    /**
     * @return list<SalesOrderLine>
     */
    private static function linesFromQuote(?Quote $quote): array
    {
        if ($quote === null) {
            throw new ValidationException('A sales order needs lines, or a quote to take them from.');
        }

        return self::linesFrom(
            $quote->lines->map(static fn ($line): array => [
                'description' => (string) $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price_native,
                'sku' => $line->sku,
            ])->all()
        );
    }
}
