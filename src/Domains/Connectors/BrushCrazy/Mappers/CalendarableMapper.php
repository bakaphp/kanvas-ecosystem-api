<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Support\CalendarableName;
use Kanvas\Connectors\BrushCrazy\Support\EventInstant;

/**
 * Turns one `lessons` / `events` / `workshops` row into the derived values an Event + EventVersion
 * need. Deliberately free of model lookups so it stays unit-testable: the sync action resolves the
 * six NOT-NULL foreign keys from its id-maps and merges them onto these arrays.
 */
class CalendarableMapper
{
    /** `events.slug` is varchar(255); leave room for the deterministic suffix. */
    private const MAX_NAME_SLUG_LENGTH = 180;

    /**
     * @return array{
     *     group_key: string,
     *     event: array<string, mixed>,
     *     version: array<string, mixed>,
     *     date: array<string, mixed>|null,
     *     instant: EventInstant|null
     * }
     */
    public static function map(
        CalendarableTypeEnum $morph,
        object $row,
        int $studioId,
        string $studioTimezone,
        Carbon $correctionCutoff,
    ): array {
        $bcId = (int) $row->id;
        $rawName = $row->name ?? null;
        $displayName = CalendarableName::displayName($rawName);
        $groupKey = self::groupKey(
            $morph,
            $row,
            $studioId,
            $displayName
        );

        $instant = EventInstant::resolve(
            $row->start ?? null,
            $row->end ?? null,
            $studioTimezone,
            $correctionCutoff,
        );

        $isDeleted = ($row->deleted_at ?? null) !== null;

        return [
            'group_key' => $groupKey,
            'event' => [
                'slug' => self::eventSlug(
                    $morph,
                    $row,
                    $studioId,
                    $displayName,
                    $bcId
                ),
                'name' => $displayName !== '' ? $displayName : "Untitled {$morph->value} {$bcId}",
                'description' => $row->description ?? null,
                'classification' => self::classification($row),
                'is_deleted' => $isDeleted,
            ],
            'version' => [
                'slug' => "bc-{$morph->value}-v{$bcId}",
                // The version column is the source id, so the unique key
                // (apps_id, companies_id, slug, event_id, version) is deterministic with no lookup.
                'version' => $bcId,
                'version_number' => 1,
                'name' => trim((string) ($rawName ?? '')) !== '' ? trim((string) $rawName) : $displayName,
                'description' => $row->description ?? null,
                'classification' => self::classification($row),
                'start_at' => $instant?->startAt,
                'end_at' => $instant?->endAt,
                'metadata' => self::versionMetadata(
                    $morph,
                    $row,
                    $studioId,
                    $instant,
                    $correctionCutoff
                ),
                'is_deleted' => $isDeleted,
            ],
            'date' => $instant === null ? null : [
                'event_date' => $instant->localDate(),
                'start_time' => $instant->localStartTime(),
                'end_time' => $instant->localEndTime() ?? $instant->localStartTime(),
            ],
            'instant' => $instant,
        ];
    }

    /**
     * Classes and workshops are the same session run repeatedly, so they collapse into one Event
     * keyed on the painting. Measured against production, 100% of workshops and 97.6% of private
     * events have no painting_id, so the name fallback is the primary path rather than an edge
     * case — which is exactly why it has to be canonicalised through displayName() first.
     */
    private static function groupKey(
        CalendarableTypeEnum $morph,
        object $row,
        int $studioId,
        string $displayName,
    ): string {
        if (! $morph->isGrouped()) {
            return "{$morph->value}:s{$studioId}:i" . (int) $row->id;
        }

        $paintingId = $row->painting_id ?? null;

        return $paintingId !== null
            ? "{$morph->value}:s{$studioId}:p" . (int) $paintingId
            : "{$morph->value}:s{$studioId}:n" . substr(md5(mb_strtolower($displayName)), 0, 10);
    }

    private static function eventSlug(
        CalendarableTypeEnum $morph,
        object $row,
        int $studioId,
        string $displayName,
        int $bcId,
    ): string {
        if (! $morph->isGrouped()) {
            return "bc-{$morph->value}-s{$studioId}-{$bcId}";
        }

        $paintingId = $row->painting_id ?? null;

        if ($paintingId !== null) {
            return "bc-{$morph->value}-s{$studioId}-p" . (int) $paintingId;
        }

        $nameSlug = mb_substr(Str::slug($displayName), 0, self::MAX_NAME_SLUG_LENGTH);
        $hash = substr(md5(mb_strtolower($displayName)), 0, 10);

        return "bc-{$morph->value}-s{$studioId}-{$nameSlug}-{$hash}";
    }

    private static function classification(object $row): ?string
    {
        $type = trim((string) ($row->type ?? ''));

        return $type !== '' ? $type : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function versionMetadata(
        CalendarableTypeEnum $morph,
        object $row,
        int $studioId,
        ?EventInstant $instant,
        Carbon $correctionCutoff,
    ): array {
        $metadata = [
            // Kanvas reads capacity from this exact key (EventVersion::getMaxCapacity()).
            'max_capacity' => (int) ($row->occupancy ?? 0),
            'bc_calendarable_id' => (int) $row->id,
            'bc_calendarable_type' => $morph->value,
            'bc_studio_id' => $studioId,
            'bc_painting_id' => isset($row->painting_id) ? (int) $row->painting_id : null,
            'rsvp' => (bool) ($row->rsvp ?? false),
            'private' => (bool) ($row->private ?? false),
            'fundraiser' => (int) ($row->fundraiser ?? 0),
            // Never carry the plaintext gate password over; only whether one was set.
            'has_password' => trim((string) ($row->password ?? '')) !== '',
            'published_at' => $row->published_at ?? null,
        ];

        if ($instant !== null) {
            $metadata += $instant->semanticsMetadata($correctionCutoff);
        }

        return $morph->hasApprovalLadder()
            ? $metadata + self::privateEventMetadata($row)
            : $metadata;
    }

    /**
     * Columns that exist only on `events`. Kept in metadata rather than dropped because the
     * approval/deposit ladder and the off-site address are what make a private party auditable.
     *
     * @return array<string, mixed>
     */
    private static function privateEventMetadata(object $row): array
    {
        return [
            'occasion' => $row->occasion ?? null,
            'company_name' => $row->company_name ?? null,
            'organizer_name' => $row->organizer_name ?? null,
            'organizer_email' => $row->organizer_email ?? null,
            'organizer_phone' => $row->organizer_phone ?? null,
            'sponsor_name' => $row->sponsor_name ?? null,
            'sponsor_email' => $row->sponsor_email ?? null,
            'sponsor_link' => $row->sponsor_link ?? null,
            'chair_count' => isset($row->chair_count) ? (int) $row->chair_count : null,
            'handicap_accommodations' => (bool) ($row->handicap_accommodations ?? false),
            'customer_notes' => $row->customer_notes ?? null,
            'location' => $row->location ?? null,
            'approved_at' => $row->approved_at ?? null,
            'finalized_at' => $row->finalized_at ?? null,
            'deposit_requested_at' => $row->deposit_requested_at ?? null,
            'deposit_paid_at' => $row->deposit_paid_at ?? null,
            'bc_sale_id' => isset($row->sale_id) ? (int) $row->sale_id : null,
            'bc_requester_id' => isset($row->requester_id) ? (int) $row->requester_id : null,
        ];
    }
}
