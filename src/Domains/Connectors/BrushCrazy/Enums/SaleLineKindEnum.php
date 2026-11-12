<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;

/**
 * What a `sale_items` row sold. BrushCrazy has no column for it: `product_name` and
 * `category_name` are the real classifiers, plus the `option_type` morph for seats and paintings.
 * The matches below are the ones BrushCrazy's own code uses (SaleItem::isDeposit(),
 * SaleItem::isRegistration(), Sale::isAmountRefund(), the gift-card reports).
 *
 * Every Kanvas OrderItem needs a variant, so each kind also names the generic catalog line a sale
 * item lands on when it has no BrushCrazy product behind it.
 */
enum SaleLineKindEnum: string
{
    case REFUND = 'refund';
    case TIP = 'tip';
    case GIFT_CARD = 'gift-card';
    case EVENT_DEPOSIT = 'event-deposit';
    case CLASS_SEAT = 'class-seat';
    case PAINTING = 'painting';
    case MISC = 'misc';

    /**
     * Order matters: a deposit or a gift card can also carry an option, and the category is the
     * stronger signal.
     */
    public static function classify(object $item): self
    {
        $productName = (string) ($item->product_name ?? '');
        $category = (string) ($item->category_name ?? '');
        $optionType = $item->option_type ?? null;

        return match (true) {
            $category === 'Refund' => self::REFUND,
            $category === 'Tips' => self::TIP,
            $productName === 'Gift Card' || $category === 'Gift Cards' => self::GIFT_CARD,
            $productName === 'Event Deposit' || in_array($category, ['Event Deposits', 'Party Deposits'], true) => self::EVENT_DEPOSIT,
            CalendarableTypeEnum::tryFrom((string) $optionType) !== null && ($item->option_id ?? null) !== null => self::CLASS_SEAT,
            $optionType === PaintingMapper::PAINTING_MORPH => self::PAINTING,
            default => self::MISC,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::REFUND => 'Refund',
            self::TIP => 'Tip',
            self::GIFT_CARD => 'Gift Card',
            self::EVENT_DEPOSIT => 'Event Deposit',
            self::CLASS_SEAT => 'Class Seat',
            self::PAINTING => 'Painting Choice',
            self::MISC => 'Miscellaneous',
        };
    }

    /** BRUSHCRAZY_LOOKUP_KEY of the generic Product/Variant this kind falls back to. */
    public function lookupKey(): string
    {
        return 'sale-line:' . $this->value;
    }

    public static function productLookupKey(int $bcProductId): string
    {
        return 'product:' . $bcProductId;
    }
}
