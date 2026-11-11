<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;

/**
 * Bulk side of the registration mirror. Registrations carry no studio, so this walks the studio's
 * calendarables and hands each chunk to SyncRegistrationFromBrushCrazyAction::syncCalendarables(),
 * the same path the webhook uses. Calendarables must be imported first: a registration resolves
 * its event version by BRUSHCRAZY_CALENDARABLE_KEY.
 *
 * A failing calendarable is caught inside the sync and counted; the rest of the chunk carries on.
 *
 * A registration moved to another class (RegistrationController updates calendarable_id) leaves
 * nothing at the source pointing to the old class, so an incremental pull only fixes the new one;
 * the old class keeps the seats until a full pull.
 */
class PullRegistrationsFromBrushCrazyAction
{
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected ?Carbon $since = null,
        protected int $chunkSize = 500,
    ) {
    }

    /**
     * @return array{synced: int, participants: int, failed: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public function execute(?callable $onProgress = null): array
    {
        $client = new Client($this->app);
        $sync = new SyncRegistrationFromBrushCrazyAction($this->app, $this->user, $this->context);
        $results = ['synced' => 0, 'participants' => 0, 'failed' => 0, 'errors' => [], 'warnings' => []];

        foreach (CalendarableTypeEnum::cases() as $morph) {
            $table = $morph->table();
            $query = $client->table($table)
                ->select($table . '.id')
                ->where($table . '.studio_id', $this->context->bcStudioId);

            if ($this->since !== null) {
                $this->applySince(
                    $query,
                    $morph,
                    $this->since->copy()->utc()->format('Y-m-d H:i:s')
                );
            }

            $query->chunkById(
                $this->chunkSize,
                function ($rows) use ($client, $sync, $morph, &$results, $onProgress) {
                    $chunk = $sync->syncCalendarables(
                        $client,
                        array_map(fn ($row) => $morph->value . ':' . $row->id, $rows->all())
                    );

                    $results['synced'] += $chunk['synced'];
                    $results['participants'] += $chunk['participants'];
                    $results['failed'] += $chunk['failed'];
                    array_push($results['errors'], ...$chunk['errors']);
                    array_push($results['warnings'], ...$chunk['warnings']);

                    if ($onProgress !== null) {
                        $onProgress($morph, $rows->count());
                    }
                },
                $table . '.id',
                'id'
            );
        }

        $orphans = $client->table('registration_attendees')->whereNull('registration_id')->count();

        if ($orphans > 0) {
            $results['warnings'][] = $orphans . ' registration_attendees with no registration (BrushCrazy-wide, not tied to any studio); not mirrored';
        }

        return $results;
    }

    /**
     * A calendarable's seats change without its own row changing: a new or refunded registration
     * (a refund soft-deletes it, which bumps updated_at), a check-in on an attendee, a sale moved
     * to another customer, or a single seat line refunded. The last one touches the sale only when
     * the line is saved (SaleItem::$touches); a quantity decrement bumps the line alone, so the
     * line's own updated_at is in the window too.
     */
    protected function applySince(Builder $query, CalendarableTypeEnum $morph, string $since): void
    {
        $table = $morph->table();

        $query->where(
            fn (Builder $changed) => $changed->whereExists(
                fn (Builder $registrations) => $registrations->selectRaw('1')
                    ->from('registrations')
                    ->where('registrations.calendarable_type', $morph->value)
                    ->whereColumn('registrations.calendarable_id', $table . '.id')
                    ->where(
                        fn (Builder $window) => $window->where('registrations.updated_at', '>=', $since)
                            ->orWhereExists(
                                fn (Builder $attendees) => $attendees->selectRaw('1')
                                    ->from('registration_attendees')
                                    ->whereColumn('registration_attendees.registration_id', 'registrations.id')
                                    ->where('registration_attendees.updated_at', '>=', $since)
                            )
                            ->orWhereExists(
                                fn (Builder $sales) => $sales->selectRaw('1')
                                    ->from('sales')
                                    ->whereColumn('sales.id', 'registrations.sale_id')
                                    ->where('sales.updated_at', '>=', $since)
                            )
                    )
            )->orWhereExists(
                fn (Builder $lines) => $lines->selectRaw('1')
                    ->from('sale_items')
                    ->where('sale_items.option_type', $morph->value)
                    ->whereColumn('sale_items.option_id', $table . '.id')
                    ->where('sale_items.updated_at', '>=', $since)
            )
        );
    }
}
