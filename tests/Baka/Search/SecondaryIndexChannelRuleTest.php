<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\Contracts\SecondaryIndexServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * Proves the Rule-level wiring designed for the "mirror a channel into a secondary search index" use
 * case. Two independent producers fire the SAME WorkflowEnum::VARIANT_CHANNEL_SAVED event with the
 * SAME flat `channel_id`/`channel_slug` params, so one Rule with one plain condition
 * (`channel_id == X`) covers both:
 *   - VariantsChannelObserver, when a variant is added to a channel or its channel-row is updated
 *     (AddVariantToChannelAction's updateOrCreate is the same write for both).
 *   - ProductsObserver, when the product itself is edited — a plain save carries no channel context,
 *     so the observer looks up the product's current channel memberships and notifies per channel.
 * A product configured with two Rules for two different targets must update both when the trigger
 * fires, independent of engine.
 */
final class SecondaryIndexChannelRuleTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'inventory', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        RecordingPushEntityToSecondaryIndexActivity::$fakeService = null;
    }

    public function testAddingAVariantToTheChannelIndexesTheProduct(): void
    {
        [$product, $channel] = $this->createProductWithChannel();

        $service = $this->createMock(SecondaryIndexServiceInterface::class);
        $service->expects($this->once())
            ->method('indexEntity')
            ->with(
                $this->callback(fn (Products $entity): bool => $entity->getId() === $product->getId()),
                'popular_index',
            );
        RecordingPushEntityToSecondaryIndexActivity::$fakeService = $service;

        $this->createSecondaryIndexRule($product, WorkflowEnum::VARIANT_CHANNEL_SAVED, $channel->getId(), 'popular_index');

        $this->addVariantToChannel($product, $channel);
    }

    public function testUpdatingAProductAlreadyInTheChannelReindexesIt(): void
    {
        [$product, $channel] = $this->createProductWithChannel();
        $this->addVariantToChannel($product, $channel);

        $service = $this->createMock(SecondaryIndexServiceInterface::class);
        $service->expects($this->once())
            ->method('indexEntity')
            ->with(
                $this->callback(fn (Products $entity): bool => $entity->getId() === $product->getId()),
                'popular_index',
            );
        RecordingPushEntityToSecondaryIndexActivity::$fakeService = $service;

        // Same trigger and same plain condition as "added to channel" — ProductsObserver::saved()
        // notifies per active channel membership on every product save, so a plain edit (no channel
        // context of its own) reaches the exact same Rule.
        $this->createSecondaryIndexRule($product, WorkflowEnum::VARIANT_CHANNEL_SAVED, $channel->getId(), 'popular_index');

        $product->name = 'Updated name ' . uniqid();
        $product->save();
    }

    public function testProductConfiguredForTwoSecondaryIndexesUpdatesBothOnTheSameTrigger(): void
    {
        [$product, $channel] = $this->createProductWithChannel();

        $service = $this->createMock(SecondaryIndexServiceInterface::class);
        $service->expects($this->exactly(2))
            ->method('indexEntity')
            ->with(
                $this->callback(fn (Products $entity): bool => $entity->getId() === $product->getId()),
                $this->callback(fn (string $index): bool => in_array($index, ['popular_algolia_index', 'popular_typesense_index'], true)),
            );
        RecordingPushEntityToSecondaryIndexActivity::$fakeService = $service;

        $this->createSecondaryIndexRule($product, WorkflowEnum::VARIANT_CHANNEL_SAVED, $channel->getId(), 'popular_algolia_index', 'algolia');
        $this->createSecondaryIndexRule($product, WorkflowEnum::VARIANT_CHANNEL_SAVED, $channel->getId(), 'popular_typesense_index', 'typesense');

        $this->addVariantToChannel($product, $channel);
    }

    public function testAddingAVariantToADifferentChannelDoesNotMatchTheRule(): void
    {
        [$product, $channel] = $this->createProductWithChannel();

        $service = $this->createMock(SecondaryIndexServiceInterface::class);
        $service->expects($this->never())->method('indexEntity');
        RecordingPushEntityToSecondaryIndexActivity::$fakeService = $service;

        // Rule is scoped to a different channel id than the one the variant actually joins.
        $this->createSecondaryIndexRule($product, WorkflowEnum::VARIANT_CHANNEL_SAVED, $channel->getId() + 999999, 'popular_index');

        $this->addVariantToChannel($product, $channel);
    }

    /**
     * @return array{0: Products, 1: Channels}
     */
    private function createProductWithChannel(): array
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $user = auth()->user();

        // Also runs Kanvas\Inventory\Support\Setup, which provisions the company's default
        // channel/region/warehouse — reused below instead of hand-rolling them.
        $this->setIntegration($app, IntegrationsEnum::INTERNAL, InternalHandler::class, $company, $user);

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        $channel = Channels::getDefault($company, $app);
        $channel->slug = 'popular';
        $channel->save();

        return [$product, $channel];
    }

    private function addVariantToChannel(Products $product, Channels $channel): VariantsChannels
    {
        $variant = $product->variants()->first();
        $warehouse = Warehouses::where('companies_id', $product->companies_id)
            ->where('apps_id', $product->apps_id)
            ->firstOrFail();

        $variantWarehouse = VariantsWarehouses::create([
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'quantity' => 10,
            'price' => 10.00,
            'position' => 0,
        ]);

        return VariantsChannels::updateOrCreate(
            [
                'product_variants_warehouse_id' => $variantWarehouse->getId(),
                'channels_id' => $channel->getId(),
            ],
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
                'price' => 10.00,
                'discounted_price' => 0.00,
                'is_published' => 1,
                'is_deleted' => 0,
            ]
        );
    }

    private function createSecondaryIndexRule(
        Products $product,
        WorkflowEnum $trigger,
        int $channelId,
        string $indexName,
        string $searchEngine = 'algolia',
    ): Rule {
        $ruleType = RuleType::firstOrCreate(['name' => $trigger->value]);
        $systemModule = SystemModulesRepository::getByModelName(Products::class, app(Apps::class));

        $rule = Rule::factory()->create([
            'systems_modules_id' => $systemModule->getId(),
            'rules_types_id' => $ruleType->getId(),
            'companies_id' => $product->companies_id,
            'apps_id' => $product->apps_id,
            'pattern' => '1',
            'params' => ['index_name' => $indexName, 'search_engine' => $searchEngine],
            'is_async' => false,
        ]);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'channel_id',
            'operator' => '==',
            'value' => (string) $channelId,
        ]);

        $action = Action::factory()->create([
            'model_name' => RecordingPushEntityToSecondaryIndexActivity::class,
            'name' => 'Push Entity To Secondary Index (test double)',
        ]);

        $ruleWorkflowAction = RuleWorkflowAction::factory()->create([
            'actions_id' => $action->getId(),
            'system_modules_id' => $systemModule->getId(),
        ]);

        RuleAction::factory()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            'weight' => 0,
        ]);

        return $rule;
    }
}
