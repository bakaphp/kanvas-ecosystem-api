<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Connectors\BrushCrazy\Actions\PullCustomersFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullPaintingsFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\ResolveStudioContextsAction;
use Kanvas\Connectors\BrushCrazy\Actions\RetireMovedBrushCrazyEntityAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCommerceCatalogFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCustomerFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncPaintingFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncRegistrationFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncStaffFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\CorrectionCutoff;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\SaleSource;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use Throwable;

/**
 * Receives mirror pointers from BrushCrazy and re-reads each entity from the source database.
 *
 * The payload carries ids, not data — so this job resolves current state rather than applying a
 * diff. That makes it order-insensitive and replay-safe (three pointers to the same row converge
 * on one result), lets a single pointer stand in for a bulk operation like a refund, and keeps the
 * mirror and the batch import on one write path that cannot drift apart.
 *
 * One receiver exists per studio, so `$this->receiver->company` already identifies the tenant and
 * ProcessWebhookJob has scoped the app and branch before execute() runs.
 */
#[WorkflowAction(name: 'BrushCrazy Mirror Webhook')]
class ProcessBrushCrazyWebhookJob extends ProcessWebhookJob
{
    /** BrushCrazy's morph aliases (enforceMorphMap). */
    private const string USER_MORPH = 'user';
    private const string STUDIO_EMPLOYEE_MORPH = 'studio-employee';
    private const string SUBSTRATE_MORPH = 'calendarable-substrate';
    private const int REPLAY_WINDOW_SECONDS = 300;

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $configuration = $receiver->configuration ?? [];
        $secret = (string) ($configuration['signing_secret'] ?? '');
        $signature = (string) $request->headers->get('X-BrushCrazy-Signature', '');
        $timestamp = (string) $request->headers->get('X-BrushCrazy-Timestamp', '');

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // The timestamp is inside the signed string, so a captured request is only replayable
        // within the window and cannot be re-dated.
        if (abs(time() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        // hash_equals, not ===: a timing-safe compare is the point of signing at all.
        return hash_equals(self::signature($timestamp, $request->getContent(), $secret), $signature);
    }

    public static function signature(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * A pointer can feed more than one route — a `sale` re-syncs its Order and the participations
     * of every class it bought seats in — so routes are checked independently, not as a chain.
     *
     * Sales and registrations are collected and synced once after the loop: the classes and
     * products in the same batch have to exist first, every child row of one sale (items, payments,
     * taxes) points at the same order, and one registrations call means one total_attendees
     * recount per class.
     */
    public function execute(): array
    {
        $events = $this->webhookRequest->payload['events'] ?? [];
        $results = [
            'synced' => 0,
            'skipped' => 0,
            'missing' => 0,
            'misrouted' => 0,
            RetireMovedBrushCrazyEntityAction::MOVED => 0,
            RetireMovedBrushCrazyEntityAction::NEEDS_ATTENTION => 0,
            'failed' => 0,
        ];

        if ($events === []) {
            return $results;
        }

        $app = $this->receiver->app;
        $pausedReason = $this->pausedReason();

        // An expected state, not a fault: returned rather than thrown so it never reaches Sentry.
        if ($pausedReason !== null) {
            $results['skipped'] = count($events);
            $results['reason'] = $pausedReason;

            return $results;
        }

        $context = $this->studioContext();
        $user = $this->receiver->user;
        $client = new Client($app);
        $correctionCutoff = CorrectionCutoff::stored($app);
        $retire = new RetireMovedBrushCrazyEntityAction($app, $context);
        $cutoffMissing = false;
        $calendarableKeys = [];
        $saleIds = [];

        foreach ($events as $event) {
            $entityType = (string) ($event['entity_type'] ?? '');
            $entityId = (int) ($event['entity_id'] ?? 0);
            $handled = false;

            if ($entityType === self::USER_MORPH) {
                $handled = true;
                $customers ??= new SyncCustomerFromBrushCrazyAction($app, $user, $context);
                $staff ??= new SyncStaffFromBrushCrazyAction($app, $user, $context);

                $this->syncUser(
                    $client,
                    $customers,
                    $staff,
                    $entityId,
                    $context->bcStudioId,
                    $results
                );
            }

            if ($entityType === self::STUDIO_EMPLOYEE_MORPH) {
                $handled = true;
                $staff ??= new SyncStaffFromBrushCrazyAction($app, $user, $context);

                $this->syncEmployment(
                    $client,
                    $staff,
                    $retire,
                    $entityId,
                    $context->bcStudioId,
                    $results
                );
            }

            if (in_array($entityType, [PaintingMapper::PAINTING_MORPH, PaintingMapper::DIRECTION_MORPH], true)) {
                $handled = true;
                $paintings ??= new SyncPaintingFromBrushCrazyAction(
                    $app,
                    $user,
                    $context,
                    new PaintingRelations($client),
                );

                $this->syncPainting(
                    $client,
                    $paintings,
                    $entityType,
                    $entityId,
                    $context->bcStudioId,
                    $results
                );
            }

            try {
                [$morph, $calendarableId] = $this->calendarableTarget($client, $entityType, $entityId);
            } catch (Throwable $e) {
                [$morph, $calendarableId] = [null, 0];
                $handled = true;
                $results['failed']++;
                $results['errors'][] = $entityType . ':' . $entityId . ' — ' . $e->getMessage();
            }

            if ($entityType === self::SUBSTRATE_MORPH && $morph === null && ! $handled) {
                $handled = true;
                $results['missing']++;
            }

            if ($morph !== null && $correctionCutoff === null) {
                $handled = true;
                $results['skipped']++;
                $cutoffMissing = true;
            }

            if ($morph !== null && $correctionCutoff !== null) {
                $handled = true;
                $calendarables ??= new SyncCalendarableFromBrushCrazyAction(
                    $app,
                    $user,
                    $context,
                    $correctionCutoff,
                    client: $client,
                );

                $elsewhere = $this->syncOne(
                    $client,
                    $calendarables,
                    $retire,
                    $morph,
                    $calendarableId,
                    $context->bcStudioId,
                    $results
                );

                // A transfer moves a registration to another class, so the old class only drops
                // the moved seats when its own registrations are re-read on its pointer.
                if (! $elsewhere) {
                    $calendarableKeys[] = $morph->value . ':' . $calendarableId;
                }
            }

            if (in_array($entityType, SyncRegistrationFromBrushCrazyAction::POINTER_MORPHS, true)) {
                $handled = true;

                try {
                    $calendarableKeys = [
                        ...$calendarableKeys,
                        ...SyncRegistrationFromBrushCrazyAction::calendarablesFor($client, $entityType, $entityId),
                    ];
                } catch (Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = $entityType . ':' . $entityId . ' — ' . $e->getMessage();
                }
            }

            if (SaleSource::handlesMorph($entityType)) {
                $handled = true;
                $saleSource ??= new SaleSource($client);

                try {
                    $saleId = $saleSource->rootSaleId($entityType, $entityId);

                    if ($saleId === null) {
                        $results['missing']++;
                    } else {
                        $saleIds[$saleId] = true;
                    }
                } catch (Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = $entityType . ':' . $entityId . ' — ' . $e->getMessage();
                }
            }

            if (in_array($entityType, SyncCommerceCatalogFromBrushCrazyAction::MORPHS, true)) {
                $handled = true;
                $catalog ??= $this->catalog($context, $client);

                $this->countOutcome(
                    fn () => $this->syncCatalog(
                        $client,
                        $catalog,
                        $retire,
                        $entityType,
                        $entityId
                    ),
                    $entityType,
                    $entityId,
                    $results
                );
            }

            // Counted, not silently dropped, so a pointer type nobody handles is visible in the
            // receiver log (shifts, pos settings, studio owners: covered by the periodic pull).
            if (! $handled) {
                $results['skipped']++;
            }
        }

        if ($saleIds !== []) {
            // One catalog for the product pointers and the sales, or the sale sync builds its own
            // and resolves every product a second time.
            $sales = new SyncSaleFromBrushCrazyAction(
                $app,
                $user,
                $context,
                catalog: $catalog ?? $this->catalog($context, $client),
                source: $saleSource,
            );

            foreach (array_keys($saleIds) as $saleId) {
                $this->countOutcome(
                    fn () => $this->syncSale($sales, $retire, $saleId),
                    SaleSource::SALE_MORPH,
                    $saleId,
                    $results
                );
            }
        }

        if ($calendarableKeys !== []) {
            $this->syncRegistrations(
                $client,
                new SyncRegistrationFromBrushCrazyAction(
                    $app,
                    $user,
                    $context,
                    customers: $customers ?? null,
                ),
                array_values(array_unique($calendarableKeys)),
                $results
            );
        }

        foreach ($retire->attention() as $message) {
            $results['warnings'][] = $message;
        }

        if ($cutoffMissing) {
            $results['warnings'][] = 'Timezone correction cutoff is not pinned; class pointers were skipped. Run kanvas:brushcrazy-import with calendarables first.';
        }

        return $results;
    }

    /**
     * The mirror is permanent and bidirectional, so these are pauses, not a handover: an operator's
     * per-studio switch (BRUSHCRAZY_SYNC_ENABLED), the app-wide kill switch (BRUSHCRAZY_CUTOVER_AT),
     * or a studio not fully provisioned yet. All are expected states, so each returns a reason
     * instead of throwing a ValidationException that reaches Sentry on every delivery.
     */
    protected function pausedReason(): ?string
    {
        $app = $this->receiver->app;
        $company = $this->receiver->company;

        if (! $company->getBool(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value)) {
            return 'BrushCrazy sync is disabled for this company.';
        }

        if (CorrectionCutoff::isPastCutover($app)) {
            return 'BrushCrazy mirror is switched off for the app (BRUSHCRAZY_CUTOVER_AT ' . CorrectionCutoff::cutoverAt($app)->toIso8601String() . ').';
        }

        $bcStudioId = $company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

        // Not cast first: (int) null is studio 0, the Corporate studio, a real tenant of its own.
        if ($bcStudioId === null || $bcStudioId === '') {
            return 'Company has no BRUSHCRAZY_STUDIO_ID; run kanvas:brushcrazy-import for its studio first.';
        }

        if (! Client::isConfigured($app)) {
            return 'BrushCrazy database configuration is missing; run the BrushCrazy connector setup first.';
        }

        if (ResolveStudioContextsAction::themeAreaForStudio($app, $company, (int) $bcStudioId) === null) {
            return "BrushCrazy studio {$bcStudioId} has no theme area; run the studio import first.";
        }

        return null;
    }

    protected function catalog(StudioContext $context, Client $client): SyncCommerceCatalogFromBrushCrazyAction
    {
        return new SyncCommerceCatalogFromBrushCrazyAction(
            $this->receiver->app,
            $this->receiver->user,
            $context,
            client: $client,
        );
    }

    /**
     * A sale BrushCrazy moved to another studio reads as misrouted here; if this company still has
     * the mirror's copy, the pointer is the vacated-studio one and the copy is retired.
     */
    protected function syncSale(SyncSaleFromBrushCrazyAction $sales, RetireMovedBrushCrazyEntityAction $retire, int $saleId): string
    {
        $outcome = $sales->fromSaleId($saleId);

        return $outcome === SyncSaleFromBrushCrazyAction::MISROUTED ? $retire->sale($saleId) : $outcome;
    }

    protected function syncCatalog(
        Client $client,
        SyncCommerceCatalogFromBrushCrazyAction $catalog,
        RetireMovedBrushCrazyEntityAction $retire,
        string $entityType,
        int $entityId
    ): string {
        $outcome = $catalog->fromPointer($entityType, $entityId);

        return $outcome === SyncSaleFromBrushCrazyAction::MISROUTED
            && $entityType === SyncCommerceCatalogFromBrushCrazyAction::PRODUCT_SETTING_MORPH
                ? $retire->productSetting($client, $catalog, $entityId)
                : $outcome;
    }

    /**
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function countOutcome(
        callable $sync,
        string $entityType,
        int $entityId,
        array &$results
    ): void {
        try {
            $results[$sync()]++;
        } catch (Throwable $e) {
            $results['failed']++;
            $results['errors'][] = $entityType . ':' . $entityId . ' — ' . $e->getMessage();
        }
    }

    /**
     * @param  array<int, string>  $calendarableKeys
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function syncRegistrations(
        Client $client,
        SyncRegistrationFromBrushCrazyAction $sync,
        array $calendarableKeys,
        array &$results
    ): void {
        try {
            $outcome = $sync->syncCalendarables($client, $calendarableKeys);
        } catch (Throwable $e) {
            $results['failed']++;
            $results['errors'][] = 'registrations — ' . $e->getMessage();

            return;
        }

        foreach (['synced', 'missing', 'misrouted', 'failed'] as $key) {
            $results[$key] += $outcome[$key] ?? 0;
        }

        $results['participants'] = ($results['participants'] ?? 0) + ($outcome['participants'] ?? 0);

        foreach (['errors', 'warnings'] as $key) {
            foreach ($outcome[$key] ?? [] as $message) {
                $results[$key][] = $message;
            }
        }
    }

    /**
     * The pointer is a `studio_employees` row id: a hire, transfer or termination in one studio.
     * A row that now belongs to another studio is either the vacated-studio pointer for a
     * Facilitator this company has, or genuinely misrouted.
     *
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function syncEmployment(
        Client $client,
        SyncStaffFromBrushCrazyAction $staff,
        RetireMovedBrushCrazyEntityAction $retire,
        int $employmentId,
        int $bcStudioId,
        array &$results,
    ): void {
        try {
            $row = $client->table('studio_employees')->where('id', $employmentId)->first(['studio_id', 'user_id']);

            if ($row === null) {
                $results['missing']++;

                return;
            }

            if ($row->user_id === null) {
                $results['misrouted']++;

                return;
            }

            if ((int) $row->studio_id !== $bcStudioId) {
                $results[$retire->employee($client, $staff, (int) $row->user_id)]++;

                return;
            }

            $results[$staff->fromUserId($client, (int) $row->user_id) !== null ? 'synced' : 'misrouted']++;
        } catch (Throwable $e) {
            $results['failed']++;
            $results['errors'][] = self::STUDIO_EMPLOYEE_MORPH . ':' . $employmentId . ' — ' . $e->getMessage();
        }
    }

    /**
     * A ticket option lives on its class's version as a snapshot, so a substrate pointer re-syncs
     * the class that owns it. Soft-deleted options still resolve, which is what drops them.
     *
     * @return array{0: CalendarableTypeEnum|null, 1: int}
     */
    protected function calendarableTarget(Client $client, string $entityType, int $entityId): array
    {
        $morph = CalendarableTypeEnum::tryFrom($entityType);

        if ($morph !== null) {
            return [$morph, $entityId];
        }

        if ($entityType !== self::SUBSTRATE_MORPH) {
            return [null, 0];
        }

        $owner = $client->table('calendarable_substrates')
            ->where('id', $entityId)
            ->first(['calendarable_type', 'calendarable_id']);

        return $owner !== null
            ? [CalendarableTypeEnum::tryFrom((string) $owner->calendarable_type), (int) $owner->calendarable_id]
            : [null, 0];
    }

    /**
     * True when the class belongs to another studio, so there are no registrations to re-read here
     * (the registration sync would only count it misrouted a second time).
     *
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function syncOne(
        Client $client,
        SyncCalendarableFromBrushCrazyAction $sync,
        RetireMovedBrushCrazyEntityAction $retire,
        CalendarableTypeEnum $morph,
        int $entityId,
        int $bcStudioId,
        array &$results,
    ): bool {
        try {
            $row = $client->table($morph->table())->where('id', $entityId)->first();

            if ($row === null) {
                // Hard-deleted at the source. Soft deletes still return a row (with deleted_at set)
                // and are mirrored as cancelled; only a genuine disappearance lands here.
                $results['missing']++;

                return false;
            }

            // Another studio's row: never written here. If this company has a copy, the class
            // moved away and the pointer is the vacated-studio one, so the copy is retired;
            // otherwise the row's own studio receiver gets its own pointer.
            if ((int) ($row->studio_id ?? 0) !== $bcStudioId) {
                $results[$retire->calendarable($morph->value . ':' . $entityId)]++;

                return true;
            }

            SyncCalendarableFromBrushCrazyAction::attachSubstrates($client, $morph, [$row]);
            $sync->fromRow($morph, $row);
            $results['synced']++;
        } catch (Throwable $e) {
            // One bad pointer must not abandon the rest of the batch. The source will re-emit on
            // the next change, and the periodic --since pull repairs whatever stays broken.
            $results['failed']++;
            $results['errors'][] = $morph->value . ':' . $entityId . ' — ' . $e->getMessage();
        }

        return false;
    }

    /**
     * Every studio receiver may get the same user pointer; each one syncs only if the user is a
     * customer or staff of its own studio, so a user who bought in two studios lands in both
     * companies. Misrouted only when they are neither here.
     *
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function syncUser(
        Client $client,
        SyncCustomerFromBrushCrazyAction $customers,
        SyncStaffFromBrushCrazyAction $staff,
        int $entityId,
        int $bcStudioId,
        array &$results,
    ): void {
        try {
            if (! $client->table('users')->where('id', $entityId)->exists()) {
                $results['missing']++;

                return;
            }

            $row = PullCustomersFromBrushCrazyAction::customersOfStudio($client, $bcStudioId)
                ->where('users.id', $entityId)
                ->first();

            if ($row !== null) {
                $customers->fromRow($row)->searchable();
            }

            $facilitator = $staff->fromUserId($client, $entityId);

            if ($row === null && $facilitator === null) {
                $results['misrouted']++;

                return;
            }

            $results['synced']++;
        } catch (Throwable $e) {
            $results['failed']++;
            $results['errors'][] = self::USER_MORPH . ':' . $entityId . ' — ' . $e->getMessage();
        }
    }

    /**
     * A direction pointer re-syncs its whole painting. Every studio receiver may get the same
     * pointer; each syncs it only if its own studio has run that painting.
     *
     * Photo, tag and recipe-pivot edits emit no pointer at the source (media is not in the morph
     * map, pivots have no id), so those reach Kanvas with the next painting pointer or full pull.
     *
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function syncPainting(
        Client $client,
        SyncPaintingFromBrushCrazyAction $sync,
        string $entityType,
        int $entityId,
        int $bcStudioId,
        array &$results,
    ): void {
        try {
            $paintingId = SyncPaintingFromBrushCrazyAction::paintingIdFor($client, $entityType, $entityId);

            if ($paintingId === null) {
                $results['missing']++;

                return;
            }

            $row = PullPaintingsFromBrushCrazyAction::paintingsOfStudio($client, $bcStudioId)
                ->where('paintings.id', $paintingId)
                ->first();

            if ($row === null) {
                $results['misrouted']++;

                return;
            }

            $sync->fromRow($row);
            $results['synced']++;
        } catch (Throwable $e) {
            $results['failed']++;
            $results['errors'][] = $entityType . ':' . $entityId . ' — ' . $e->getMessage();
        }
    }

    /**
     * Built from the receiver's own company rather than by re-resolving every studio, which would
     * mean reading the whole studios table on each webhook. Only called once pausedReason() has
     * confirmed the studio id and the theme area exist, so the throw is an invariant guard.
     */
    protected function studioContext(): StudioContext
    {
        $company = $this->receiver->company;
        $bcStudioId = (int) $company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

        $timezone = (string) ($company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_TIMEZONE->value)
            ?? $this->receiver->app->get(ConfigurationEnum::BRUSHCRAZY_DEFAULT_TIMEZONE->value)
            ?? 'America/Denver');

        $themeArea = ResolveStudioContextsAction::themeAreaForStudio($this->receiver->app, $company, $bcStudioId)
            ?? throw new ValidationException("BrushCrazy studio {$bcStudioId} has no theme area; run the studio import first.");

        return new StudioContext(
            bcStudioId: $bcStudioId,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: $themeArea,
            timezone: $timezone,
        );
    }
}
