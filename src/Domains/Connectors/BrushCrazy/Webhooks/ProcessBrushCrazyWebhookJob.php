<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
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
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $configuration = $receiver->configuration ?? [];
        $secret = (string) ($configuration['signing_secret'] ?? '');
        $signature = (string) $request->headers->get('X-BrushCrazy-Signature', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        // hash_equals, not ===: a timing-safe compare is the point of signing at all.
        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function execute(): array
    {
        $events = $this->webhookRequest->payload['events'] ?? [];
        $results = ['synced' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];

        if ($events === []) {
            return $results;
        }

        $context = $this->studioContext();
        $client = new Client($this->receiver->app);
        $sync = new SyncCalendarableFromBrushCrazyAction(
            $this->receiver->app,
            $this->receiver->user,
            $context,
            $this->correctionCutoff(),
        );

        foreach ($events as $event) {
            $morph = CalendarableTypeEnum::tryFrom((string) ($event['entity_type'] ?? ''));

            if ($morph === null) {
                // Customers, paintings, substrates and registrations have their own sync actions
                // still to be written; their pointers are counted, not silently dropped, so the
                // gap is visible in the receiver log.
                $results['skipped']++;

                continue;
            }

            $this->syncOne(
                $client,
                $sync,
                $morph,
                (int) ($event['entity_id'] ?? 0),
                $results
            );
        }

        return $results;
    }

    /**
     * @param  array<string, int|array<int, string>>  $results
     */
    protected function syncOne(
        Client $client,
        SyncCalendarableFromBrushCrazyAction $sync,
        CalendarableTypeEnum $morph,
        int $entityId,
        array &$results,
    ): void {
        try {
            $row = $client->table($morph->table())->where('id', $entityId)->first();

            if ($row === null) {
                // Hard-deleted at the source. Soft deletes still return a row (with deleted_at set)
                // and are mirrored as cancelled; only a genuine disappearance lands here.
                $results['missing']++;

                return;
            }

            $sync->fromRow($morph, $row);
            $results['synced']++;
        } catch (Throwable $e) {
            // One bad pointer must not abandon the rest of the batch. The source will re-emit on
            // the next change, and the periodic --since pull repairs whatever stays broken.
            $results['failed']++;
            $results['errors'][] = $morph->value . ':' . $entityId . ' — ' . $e->getMessage();
        }
    }

    /**
     * Built from the receiver's own company rather than by re-resolving every studio, which would
     * mean reading the whole studios table on each webhook.
     */
    protected function studioContext(): StudioContext
    {
        $company = $this->receiver->company;
        $bcStudioId = (int) $company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

        $timezone = (string) ($company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_TIMEZONE->value)
            ?? $this->receiver->app->get(ConfigurationEnum::BRUSHCRAZY_DEFAULT_TIMEZONE->value)
            ?? 'America/Denver');

        // By studio id, not "the company's theme area": the lookup seed gives every studio company
        // an Unassigned area too, and the import always writes the studio's own.
        $themeArea = ThemeArea::getByCustomField(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $bcStudioId, $company)
            ?? throw new ValidationException("BrushCrazy studio {$bcStudioId} has no theme area; run the studio import first.");

        return new StudioContext(
            bcStudioId: $bcStudioId,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: $themeArea,
            timezone: $timezone,
        );
    }

    /**
     * Rows starting at or after this instant get their timezone corrected to the studio's real
     * zone; earlier ones keep the stored instant. Pinned at import time so the mirror keeps
     * applying the same rule the bulk import did, instead of the boundary drifting with now().
     */
    protected function correctionCutoff(): Carbon
    {
        $stored = $this->receiver->app->get(ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value);

        return $stored !== null ? Carbon::parse((string) $stored, 'UTC') : Carbon::now('UTC');
    }
}
