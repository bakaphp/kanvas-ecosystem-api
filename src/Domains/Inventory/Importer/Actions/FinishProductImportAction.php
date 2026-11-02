<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Channels\Actions\UnPublishChannelVariantsAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Enums\ConfigurationEnum;
use Kanvas\Inventory\Importer\Enums\ProductImportRunStatusEnum;
use Kanvas\Inventory\Importer\Models\ProductImportRun;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

/**
 * Closes a run and unpublishes, in one channel, what was published before the run started and
 * that no batch of the run sent. Variants a job created during the run are left alone, so the
 * queue can still be working through batches when this runs.
 */
class FinishProductImportAction
{
    /**
     * Below this share of the live catalog the run is treated as a truncated or mis-keyed feed
     * (wrong SKU field, partial source) and nothing is unpublished.
     */
    public const float MIN_SEEN_RATIO = 0.5;

    /**
     * The ratio only applies from this many published variants. On a tiny channel a real sell-off
     * (2 of 3 cars sold) looks exactly like a truncated feed, and would be skipped every night
     * forever; at this size a bad feed can only drop a handful, which the next good run restores.
     */
    public const int MIN_PUBLISHED_FOR_RATIO = 10;

    /**
     * @param bool $force skip the ratio check for a confirmed large clearance; the company's
     *                    on/off setting still applies
     */
    public function __construct(
        protected ProductImportRun $run,
        protected Channels $channel,
        protected bool $force = false,
    ) {
    }

    public function execute(): ProductImportRun
    {
        if ($this->run->refresh()->status !== ProductImportRunStatusEnum::OPEN->value) {
            return $this->run;
        }

        $runByVariant = $this->publishedBeforeRunQuery()
            ->distinct()
            ->pluck('last_import_run_id', 'products_variants_id');

        $published = $runByVariant->count();
        $missing = $runByVariant
            ->filter(fn (mixed $runId) => (int) $runId !== $this->run->getId())
            ->keys();

        $seen = $published - $missing->count();
        $looksTruncated = ! $this->force
            && $published >= self::MIN_PUBLISHED_FOR_RATIO
            && $seen < $published * self::MIN_SEEN_RATIO;

        $skippedReason = match (true) {
            ! $this->isEnabled() => 'Unpublishing missing products is turned off for this company',
            $looksTruncated => sprintf(
                'The import matched %d of %d published variants; it looks truncated or keyed on the wrong SKU',
                $seen,
                $published
            ),
            default => null,
        };

        if ($skippedReason === null) {
            new UnPublishChannelVariantsAction($this->channel, $missing)->execute();
        }

        $this->run->update([
            'channels_id' => $this->channel->getId(),
            'status' => $skippedReason === null ? ProductImportRunStatusEnum::COMPLETED->value : ProductImportRunStatusEnum::SKIPPED->value,
            'published_count' => $published,
            'unpublished_count' => $skippedReason === null ? $missing->count() : 0,
            'skipped_reason' => $skippedReason,
            'finished_at' => now(),
        ]);

        return $this->run;
    }

    protected function publishedBeforeRunQuery(): Builder
    {
        $dontUnPublishVariantsId = $this->channel->dontUnpublishVariantIds();

        return VariantsChannels::query()
            ->where('channels_id', $this->channel->getId())
            ->where('is_published', 1)
            ->where('created_at', '<', $this->run->started_at)
            ->when(
                ! empty($dontUnPublishVariantsId),
                fn (Builder $query) => $query->whereNotIn('products_variants_id', $dontUnPublishVariantsId)
            );
    }

    protected function isEnabled(): bool
    {
        return $this->run->company->getBool(ConfigurationEnum::UNPUBLISH_MISSING_ON_FINISH->value, true);
    }
}
