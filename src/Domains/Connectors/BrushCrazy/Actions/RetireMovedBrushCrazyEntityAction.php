<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\UpsertsMirroredRows;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\SaleSource;
use Kanvas\Connectors\BrushCrazy\Support\SiteBooking;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Throwable;

/**
 * One company per studio, so a row BrushCrazy moves to another studio leaves a stale copy in the
 * old company: the new studio's sync never looks at the old one, and the old one reads the row as
 * misrouted. This retires that copy. The webhook calls it for the vacated-studio pointer, the full
 * pull for mapped ids its walk no longer reached; neither decides what "retire" means.
 *
 * Deletes are flagged BRUSHCRAZY_DELETED_BY_MIRROR, so a move back restores the same rows through
 * the normal sync instead of creating duplicates. Writes go out with events and workflows off.
 */
class RetireMovedBrushCrazyEntityAction
{
    use UpsertsMirroredRows;

    public const string MOVED = 'moved';
    public const string MISROUTED = SyncSaleFromBrushCrazyAction::MISROUTED;
    public const string NEEDS_ATTENTION = 'needs_attention';

    private const int CHUNK = 1000;

    protected IdMapRepository $idMaps;

    /** @var array<int, string> */
    protected array $attention = [];

    public function __construct(
        protected AppInterface $app,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
    }

    /**
     * A class with live site bookings (or a paid site order) is left in place: deleting it would
     * strand customers who paid in this company. The mirror's own participations go with it.
     */
    public function calendarable(string $calendarableKey): string
    {
        $versionId = $this->mirroredId(EventVersion::class, CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY, $calendarableKey);
        $version = $versionId !== null
            ? EventVersion::withTrashed()
                ->where('id', $versionId)
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->context->companyId())
                ->first()
            : null;

        if ($version === null) {
            return self::MISROUTED;
        }

        $participations = EventVersionParticipant::withTrashed()->where('event_version_id', $version->getId())->get();
        $mirrorKeys = $this->idMaps->loadReverse(
            0,
            EventVersionParticipant::class,
            CustomFieldEnum::BRUSHCRAZY_PARTICIPATION_KEY,
            $participations->modelKeys(),
        );
        [$mirror, $site] = $participations->partition(
            fn (EventVersionParticipant $row) => isset($mirrorKeys[$row->getId()])
                || isset($row->metadata['brushcrazy']['participation_key'])
        );

        if ($this->hasSiteBookings($site->all())) {
            $this->attention[] = "{$calendarableKey} — moved to another studio but has site bookings in company {$this->context->companyId()}; left in place";

            return self::NEEDS_ATTENTION;
        }

        foreach ($mirror as $participation) {
            $this->retireRow($participation);
        }

        $this->retireRow($version);

        $siblingsLive = EventVersion::query()
            ->where('event_id', $version->event_id)
            ->whereKeyNot($version->getId())
            ->where('is_deleted', 0)
            ->exists();

        if (! $siblingsLive) {
            $event = Event::withTrashed()->where('id', $version->event_id)->first();

            if ($event !== null) {
                $this->retireRow($event);
            }
        }

        return self::MOVED;
    }

    /**
     * Only an order the mirror built is retired. One the site created (BRUSHCRAZY_ORIGIN=kanvas) is
     * the customer's own purchase and is flagged instead.
     */
    public function sale(int $saleId): string
    {
        $orderId = $this->mirroredId(Order::class, CustomFieldEnum::BRUSHCRAZY_SALE_ID, $saleId);
        $order = $orderId !== null
            ? Order::withTrashed()
                ->where('id', $orderId)
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->context->companyId())
                ->first()
            : null;

        if ($order === null) {
            return self::MISROUTED;
        }

        if (! self::isMirrorOrder($order)) {
            $this->attention[] = "sale:{$saleId} — moved to another studio but order {$order->getId()} was created in Kanvas; left in place";

            return self::NEEDS_ATTENTION;
        }

        if ($this->retireRow($order)) {
            $order->unsearchable();
        }

        return self::MOVED;
    }

    /**
     * The staff sync already recomputes a user against this studio and deactivates a Facilitator
     * that is no longer staff here; a move is one more way to stop being staff.
     */
    public function employee(Client $client, SyncStaffFromBrushCrazyAction $staff, int $bcUserId): string
    {
        if ($this->mirroredId(Facilitator::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $bcUserId) === null) {
            return self::MISROUTED;
        }

        $staff->fromUserId($client, $bcUserId);

        return self::MOVED;
    }

    /**
     * Products are global and each studio only overrides the price, so a setting that left this
     * studio is a removed override: re-syncing the product falls back to what is left here.
     */
    public function productSetting(Client $client, SyncCommerceCatalogFromBrushCrazyAction $catalog, int $settingId): string
    {
        $productId = $client->table('product_settings')->where('id', $settingId)->value('product_id');

        if ($productId === null) {
            return self::MISROUTED;
        }

        $known = $this->idMaps->loadFor(
            $this->context->companyId(),
            Products::class,
            CustomFieldEnum::BRUSHCRAZY_PRODUCT_ID,
            [(int) $productId],
        );

        if ($known === []) {
            return self::MISROUTED;
        }

        $catalog->syncProducts([(int) $productId]);

        return self::MOVED;
    }

    /**
     * @param  array<int, string>  $calendarableKeys  mapped here, not seen under this studio by the caller
     * @return array{moved: int, needs_attention: int, failed: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public function sweepCalendarables(Client $client, array $calendarableKeys): array
    {
        $pending = self::pendingCalendarables(
            $client,
            $this->context->bcStudioId,
            $this->context->companyId(),
            $calendarableKeys
        );

        return $this->sweep(array_keys($pending), fn (string $key) => $this->calendarable($key));
    }

    /**
     * @param  array<int, int>  $saleIds  mapped here, not seen under this studio by the caller
     * @return array{moved: int, needs_attention: int, failed: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public function sweepSales(Client $client, array $saleIds): array
    {
        $pending = self::pendingSales(
            $client,
            $this->context->bcStudioId,
            $this->context->companyId(),
            $saleIds
        );

        return $this->sweep(
            array_keys($pending),
            fn (int $saleId) => $this->sale($saleId),
            SaleSource::SALE_MORPH . ':'
        );
    }

    /**
     * Mapped classes whose source row now sits in another studio and whose version here is still
     * live: moved, not yet retired. A source row that is gone entirely is not a move.
     *
     * @param  array<int, string>  $calendarableKeys
     * @return array<string, int> calendarable key => event version id
     */
    public static function pendingCalendarables(
        Client $client,
        int $bcStudioId,
        int $companyId,
        array $calendarableKeys
    ): array {
        $elsewhere = [];

        foreach (CalendarableTypeEnum::idsByMorph($calendarableKeys) as $morph => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $client->table(CalendarableTypeEnum::from($morph)->table())
                    ->whereIn('id', $chunk)
                    ->where('studio_id', '<>', $bcStudioId)
                    ->pluck('id')
                    ->each(function ($id) use ($morph, &$elsewhere) {
                        $elsewhere[] = CalendarableTypeEnum::from($morph)->key((int) $id);
                    });
            }
        }

        $versions = new IdMapRepository()->loadFor(
            $companyId,
            EventVersion::class,
            CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY,
            $elsewhere
        );
        $live = [];

        foreach (array_chunk(array_values($versions), self::CHUNK) as $chunk) {
            EventVersion::query()
                ->where('companies_id', $companyId)
                ->whereIn('id', $chunk)
                ->pluck('id')
                ->each(function ($id) use (&$live) {
                    $live[(int) $id] = true;
                });
        }

        return array_filter($versions, fn (int $versionId) => isset($live[$versionId]));
    }

    /**
     * Mirror-built orders only: a site order is never retired, so counting it would report it as
     * pending forever.
     *
     * @param  array<int, int>  $saleIds
     * @return array<int, int> sale id => order id
     */
    public static function pendingSales(
        Client $client,
        int $bcStudioId,
        int $companyId,
        array $saleIds
    ): array {
        $elsewhere = [];

        foreach (array_chunk(array_map('intval', $saleIds), self::CHUNK) as $chunk) {
            $client->table('sales')
                ->whereIn('id', $chunk)
                ->where('studio_id', '<>', $bcStudioId)
                ->pluck('id')
                ->each(function ($id) use (&$elsewhere) {
                    $elsewhere[] = (int) $id;
                });
        }

        $orders = new IdMapRepository()->loadFor(
            $companyId,
            Order::class,
            CustomFieldEnum::BRUSHCRAZY_SALE_ID,
            $elsewhere
        );
        $saleByOrder = array_flip($orders);
        $pending = [];

        foreach (array_chunk(array_values($orders), self::CHUNK) as $chunk) {
            Order::query()
                ->where('companies_id', $companyId)
                ->whereIn('id', $chunk)
                ->get()
                ->filter(fn (Order $order) => self::isMirrorOrder($order))
                ->each(function (Order $order) use (&$pending, $saleByOrder) {
                    $pending[(int) $saleByOrder[(int) $order->getId()]] = (int) $order->getId();
                });
        }

        return $pending;
    }

    /**
     * Drained by the caller into its own warnings, so each message is reported once.
     *
     * @return array<int, string>
     */
    public function attention(): array
    {
        $attention = $this->attention;
        $this->attention = [];

        return $attention;
    }

    private static function isMirrorOrder(Order $order): bool
    {
        return SiteBooking::isMirroredOrder($order) && ! SiteBooking::isSiteOrder($order);
    }

    /**
     * A hold that ran out is not a booking (the attendee recount skips it too); anything else live
     * is, and so is a paid site order whose participations were already released.
     *
     * @param  array<int, EventVersionParticipant>  $site
     */
    private function hasSiteBookings(array $site): bool
    {
        $now = Carbon::now('UTC');
        $orderUuids = [];

        foreach ($site as $participation) {
            $metadata = is_array($participation->metadata) ? $participation->metadata : [];

            if (is_string($metadata['order_uuid'] ?? null) && $metadata['order_uuid'] !== '') {
                $orderUuids[] = $metadata['order_uuid'];
            }

            if ($participation->is_deleted) {
                continue;
            }

            $expiredHold = in_array($metadata['state'] ?? null, ['held', 'release_pending'], true)
                && isset($metadata['hold_expires_at'])
                && Carbon::parse((string) $metadata['hold_expires_at'])->lte($now);

            if (! $expiredHold) {
                return true;
            }
        }

        return $orderUuids !== [] && Order::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId())
            ->whereIn('uuid', array_values(array_unique($orderUuids)))
            ->where('payment_status', PaymentStatusEnum::PAID->value)
            ->exists();
    }

    /**
     * True when the row was live and is now deleted.
     */
    private function retireRow(Model $row): bool
    {
        if (method_exists($row, 'disableWorkflows')) {
            $row->disableWorkflows();
        }

        if ($row->is_deleted) {
            return false;
        }

        $row->is_deleted = true;
        $row->saveQuietly();
        $this->recordMirrorDeletion($row);

        return true;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @param  callable(int|string): string  $retire
     * @return array{moved: int, needs_attention: int, failed: int, errors: array<int, string>, warnings: array<int, string>}
     */
    private function sweep(array $ids, callable $retire, string $label = ''): array
    {
        $results = ['moved' => 0, 'needs_attention' => 0, 'failed' => 0, 'errors' => [], 'warnings' => []];

        foreach ($ids as $id) {
            try {
                $outcome = $retire($id);

                if (isset($results[$outcome])) {
                    $results[$outcome]++;
                }
            } catch (Throwable $e) {
                $results['failed']++;
                $results['errors'][] = $label . $id . ' — ' . $e->getMessage();
            }
        }

        $results['warnings'] = $this->attention();

        return $results;
    }
}
