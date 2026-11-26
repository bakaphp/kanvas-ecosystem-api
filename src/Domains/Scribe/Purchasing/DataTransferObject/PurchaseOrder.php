<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder as PurchaseOrderModel;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class PurchaseOrder extends Data
{
    /**
     * @param list<PurchaseOrderLine> $lines
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $user,
        public readonly Organization $vendor,
        public readonly string $currency,
        public readonly array $lines,
        public readonly ?Carbon $order_date = null,
        public readonly ?Carbon $expected_date = null,
        public readonly ?string $notes = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromMultiple(
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $data,
    ): self {
        return new self(
            app: $app,
            company: $company,
            user: $user,
            vendor: Organization::getByIdFromCompanyApp((int) $data['vendor_id'], $company, $app),
            currency: strtoupper((string) $data['currency']),
            lines: self::linesFrom($data['lines']),
            order_date: self::dateFrom($data['order_date'] ?? null),
            expected_date: self::dateFrom($data['expected_date'] ?? null),
            notes: Str::trimToNull($data['notes'] ?? null),
        );
    }

    /**
     * The order's current values with the given changes laid over them, so a partial update keeps the rest.
     *
     * @param array<string, mixed> $changes
     */
    public static function forUpdate(
        PurchaseOrderModel $order,
        AppInterface $app,
        Users $user,
        CompanyInterface $company,
        array $changes,
    ): self {
        // A mirrored order may have no linked vendor, which this DTO cannot hold; refuse it before building.
        if (! $order->isIssuedByCompany()) {
            throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($order, 'edited');
        }

        return new self(
            app: $app,
            company: $company,
            user: $user,
            vendor: isset($changes['vendor_id'])
                ? Organization::getByIdFromCompanyApp((int) $changes['vendor_id'], $company, $app)
                : $order->vendor,
            currency: strtoupper((string) ($changes['currency'] ?? $order->currency)),
            lines: isset($changes['lines'])
                ? self::linesFrom($changes['lines'])
                : $order->lines->map(static fn ($line): PurchaseOrderLine => new PurchaseOrderLine(
                    description: (string) $line->description,
                    quantity: (float) $line->order_qty,
                    unit_cost: (float) $line->unit_cost,
                    sku: $line->sku,
                    inventory_variant_id: $line->inventory_variant_id,
                    expense_account_id: $line->expense_account_id,
                    subaccount_id: $line->subaccount_id,
                ))->all(),
            order_date: self::dateFrom($changes['order_date'] ?? null) ?? $order->order_date,
            expected_date: array_key_exists('expected_date', $changes)
                ? self::dateFrom($changes['expected_date'])
                : $order->expected_date,
            notes: array_key_exists('notes', $changes) ? Str::trimToNull($changes['notes']) : $order->notes,
        );
    }

    private static function dateFrom(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return list<PurchaseOrderLine>
     */
    private static function linesFrom(array $lines): array
    {
        if ($lines === []) {
            throw new ValidationException('A purchase order needs at least one line.');
        }

        return array_map(
            static fn (array $line): PurchaseOrderLine => new PurchaseOrderLine(
                description: (string) $line['description'],
                quantity: (float) $line['quantity'],
                unit_cost: (float) $line['unit_cost'],
                sku: Str::trimToNull($line['sku'] ?? null),
                inventory_variant_id: isset($line['inventory_variant_id']) ? (int) $line['inventory_variant_id'] : null,
                expense_account_id: isset($line['expense_account_id']) ? (int) $line['expense_account_id'] : null,
                subaccount_id: isset($line['subaccount_id']) ? (int) $line['subaccount_id'] : null,
            ),
            array_values($lines)
        );
    }
}
