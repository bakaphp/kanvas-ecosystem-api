<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Baka\Support\Str;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalEventAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalEventTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentEvent;

/**
 * A bill that came in as a vendor e-CF: its `received` fiscal row, and the commercial response we sent on it
 * as that row's one `commercial_response` event (DGII takes one answer per document).
 */
final class ReceivedEcfCompliance
{
    public const string COMMERCIAL_RESPONSE = 'commercial_response';

    public static function current(Bill $bill): ?FiscalDocument
    {
        return FiscalDocument::currentFor($bill, FiscalDirectionEnum::RECEIVED);
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(Bill $bill): array
    {
        $fiscal = self::current($bill);

        if ($fiscal === null) {
            return [];
        }

        return [
            'fiscal_document_id' => $fiscal->getKey(),
            'direction' => FiscalDirectionEnum::RECEIVED->value,
            'encf' => $fiscal->number,
            'type' => $fiscal->document_type,
            'vendor_rnc' => $fiscal->issuer_tax_id,
            'provider' => $fiscal->provider,
            'security_code' => $fiscal->authority_seal,
            'signed_at' => $fiscal->authorityValue('signed_at') ?? $fiscal->signed_at?->toIso8601String(),
            'stamp_url' => $fiscal->regimeValue('stamp_url'),
            'references' => $fiscal->regimeValue('references'),
            self::COMMERCIAL_RESPONSE => self::commercialResponse($bill),
        ];
    }

    public static function encf(Bill $bill): ?string
    {
        return Str::trimToNull(self::current($bill)?->number);
    }

    public static function vendorTaxId(Bill $bill): ?string
    {
        return Str::trimToNull(self::current($bill)?->issuer_tax_id ?? $bill->vendor_tax_id);
    }

    /**
     * Only a bill booked from the vendor's own document carries a received fiscal row, whatever its country.
     */
    public static function isReceivedEcf(Bill $bill): bool
    {
        return self::encf($bill) !== null && self::vendorTaxId($bill) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function commercialResponse(Bill $bill): ?array
    {
        $event = self::commercialResponseEvent($bill);

        return $event !== null ? (array) $event->payload : null;
    }

    /**
     * The provider writes its transport fields and the action its decision; both merge into the one event.
     *
     * @param array<string, mixed> $changes
     */
    public static function recordCommercialResponse(Bill $bill, array $changes): void
    {
        $fiscal = self::current($bill);

        if ($fiscal === null) {
            return;
        }

        $event = new RecordFiscalEventAction(
            document: $fiscal,
            type: FiscalEventTypeEnum::COMMERCIAL_RESPONSE,
            direction: 'sent',
            status: 'sent',
            dedupeKey: self::COMMERCIAL_RESPONSE . ':' . $fiscal->uuid,
            payload: $changes,
            attributes: ['requested_at' => now()],
        )->execute();

        $event->payload = [...($event->payload ?? []), ...$changes];
        $event->save();
    }

    private static function commercialResponseEvent(Bill $bill): ?FiscalDocumentEvent
    {
        $fiscal = self::current($bill);

        /** @var FiscalDocumentEvent|null $event */
        $event = $fiscal?->events()->where('event_type', FiscalEventTypeEnum::COMMERCIAL_RESPONSE->value)->latest('id')->first();

        return $event;
    }
}
