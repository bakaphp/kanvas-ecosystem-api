<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Importer\Actions\FinishProductImportAction;
use Kanvas\Inventory\Importer\Actions\RecordProductImportBatchAction;
use Kanvas\Inventory\Importer\Enums\ConfigurationEnum;
use Kanvas\Inventory\Importer\Enums\ProductImportRunStatusEnum;
use Kanvas\Inventory\Importer\Models\ProductImportRun;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

/**
 * The importProduct resolver spools to S3 (see ProductImporterTagsTest), so batches are recorded
 * through the action the resolver calls; finishProductImport is driven through GraphQL.
 */
final class ProductImportRunTest extends TestCase
{
    use PublishesChannelVariants;

    public function testFinishUnpublishesOnlyWhatNoBatchSent(): void
    {
        [$channel, [$first, $second, $sold]] = $this->publishVariantsInANewChannel(3);

        $this->recordBatch([$first]);
        $this->recordBatch([$second]);

        $this->graphQL('
            mutation($companyId: Int!, $channelId: ID!) {
                finishProductImport(companyId: $companyId, channelId: $channelId) {
                    status
                    batches_count
                    skus_count
                    published_count
                    unpublished_count
                    skipped_reason
                }
            }', [
            'companyId' => auth()->user()->getCurrentCompany()->getId(),
            'channelId' => $channel->getId(),
        ])->assertJson([
            'data' => ['finishProductImport' => [
                'status' => ProductImportRunStatusEnum::COMPLETED->value,
                'batches_count' => 2,
                'skus_count' => 2,
                'published_count' => 3,
                'unpublished_count' => 1,
                'skipped_reason' => null,
            ]],
        ]);

        $this->assertSame(1, $this->publishedIn($channel, $first));
        $this->assertSame(1, $this->publishedIn($channel, $second));
        $this->assertSame(0, $this->publishedIn($channel, $sold));
    }

    public function testAVariantAddedToTheChannelDuringTheRunIsLeftAlone(): void
    {
        [$channel, [$first, $second, $sold]] = $this->publishVariantsInANewChannel(3);

        $run = $this->recordBatch([$first, $second]);

        // A queued job publishing a brand-new vehicle after the first batch was received.
        [$newDuringRun] = $this->publishVariantsIn($channel, 1);

        new FinishProductImportAction($run, $channel)->execute();

        $this->assertSame(1, $this->publishedIn($channel, $newDuringRun));
        $this->assertSame(0, $this->publishedIn($channel, $sold));
    }

    public function testFinishUnpublishesNothingWhenTheBatchesLookTruncated(): void
    {
        [$channel, $variants] = $this->publishVariantsInANewChannel(FinishProductImportAction::MIN_PUBLISHED_FOR_RATIO);

        $run = new FinishProductImportAction($this->recordBatch([$variants[0]]), $channel)->execute();

        $this->assertSame(ProductImportRunStatusEnum::SKIPPED->value, $run->status);
        $this->assertSame(0, $run->unpublished_count);
        $this->assertStringContainsString('matched 1 of 10', (string) $run->skipped_reason);
        foreach ($variants as $variant) {
            $this->assertSame(1, $this->publishedIn($channel, $variant));
        }
    }

    public function testASmallChannelCanSellMostOfItsCars(): void
    {
        [$channel, [$kept, $soldOne, $soldTwo]] = $this->publishVariantsInANewChannel(3);

        $run = new FinishProductImportAction($this->recordBatch([$kept]), $channel)->execute();

        $this->assertSame(ProductImportRunStatusEnum::COMPLETED->value, $run->status);
        $this->assertSame(1, $this->publishedIn($channel, $kept));
        $this->assertSame(0, $this->publishedIn($channel, $soldOne));
        $this->assertSame(0, $this->publishedIn($channel, $soldTwo));
    }

    public function testForceUnpublishesALargeClearanceTheRatioWouldBlock(): void
    {
        [$channel, $variants] = $this->publishVariantsInANewChannel(FinishProductImportAction::MIN_PUBLISHED_FOR_RATIO);
        $this->recordBatch([$variants[0]]);

        $this->graphQL('
            mutation($companyId: Int!, $channelId: ID!) {
                finishProductImport(companyId: $companyId, channelId: $channelId, force: true) {
                    status
                    unpublished_count
                }
            }', [
            'companyId' => auth()->user()->getCurrentCompany()->getId(),
            'channelId' => $channel->getId(),
        ])->assertJson([
            'data' => ['finishProductImport' => [
                'status' => ProductImportRunStatusEnum::COMPLETED->value,
                'unpublished_count' => FinishProductImportAction::MIN_PUBLISHED_FOR_RATIO - 1,
            ]],
        ]);

        $this->assertSame(1, $this->publishedIn($channel, $variants[0]));
    }

    public function testFinishUnpublishesNothingWhenTheCompanyTurnedItOff(): void
    {
        [$channel, [$kept, $sold]] = $this->publishVariantsInANewChannel(2);
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::UNPUBLISH_MISSING_ON_FINISH->value, 0);

        try {
            $run = new FinishProductImportAction($this->recordBatch([$kept]), $channel)->execute();
        } finally {
            $company->del(ConfigurationEnum::UNPUBLISH_MISSING_ON_FINISH->value);
        }

        $this->assertSame(ProductImportRunStatusEnum::SKIPPED->value, $run->status);
        $this->assertSame(1, $this->publishedIn($channel, $sold));
    }

    public function testBatchesJoinTheOpenRunAndAStaleRunIsAbandoned(): void
    {
        [, [$variant]] = $this->publishVariantsInANewChannel(1);

        $first = $this->recordBatch([$variant]);
        $this->assertSame($first->getId(), $this->recordBatch([$variant])->getId());

        $this->travel(ProductImportRun::ABANDON_AFTER_MINUTES + 1)->minutes();
        $next = $this->recordBatch([$variant]);

        $this->assertNotSame($first->getId(), $next->getId());
        $this->assertSame(ProductImportRunStatusEnum::ABANDONED->value, $first->fresh()->status);
        $this->assertSame(ProductImportRunStatusEnum::OPEN->value, $next->status);
    }

    public function testARunThatNeverGoesIdleIsStillAbandonedPastItsMaxAge(): void
    {
        [, [$variant]] = $this->publishVariantsInANewChannel(1);

        // Whole seconds, so started_at (stored to the second) sits exactly on the 12h boundary.
        $this->travelTo(now()->startOfSecond());
        $first = $this->recordBatch([$variant]);

        // A batch every two hours, up to and including the cap: never idle for 3h, so only age
        // can abandon it.
        for ($hours = 2; $hours <= ProductImportRun::MAX_AGE_HOURS; $hours += 2) {
            $this->travel(2)->hours();
            $this->assertSame($first->getId(), $this->recordBatch([$variant])->getId());
        }

        $this->travel(2)->hours();
        $next = $this->recordBatch([$variant]);

        $this->assertNotSame($first->getId(), $next->getId());
        $this->assertSame(ProductImportRunStatusEnum::ABANDONED->value, $first->fresh()->status);
    }

    public function testFinishWithoutAnImportInProgressIsRejected(): void
    {
        [$channel] = $this->publishVariantsInANewChannel(1);

        $this->graphQL('
            mutation($companyId: Int!, $channelId: ID!) {
                finishProductImport(companyId: $companyId, channelId: $channelId) { id }
            }', [
            'companyId' => auth()->user()->getCurrentCompany()->getId(),
            'channelId' => $channel->getId(),
        ])->assertGraphQLErrorMessage('No product import in progress for this company; call importProduct first.');
    }

    /**
     * @param list<Variants> $variants
     */
    private function recordBatch(array $variants): ProductImportRun
    {
        $user = auth()->user();

        return new RecordProductImportBatchAction(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            RecordProductImportBatchAction::variantSkusOf([[
                'name' => 'Batch',
                'variants' => array_map(fn (Variants $variant) => ['sku' => $variant->sku], $variants),
            ]]),
        )->execute();
    }
}
