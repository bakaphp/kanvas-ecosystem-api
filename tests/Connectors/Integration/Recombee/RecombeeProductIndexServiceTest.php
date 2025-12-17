<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Recombee;

use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeInteractionService;
use Kanvas\Connectors\Recombee\Services\RecombeeItemRecommendationService;
use Kanvas\Connectors\Recombee\Services\RecombeeProductIndexService;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Tests\TestCase;

final class RecombeeProductIndexServiceTest extends TestCase
{
    protected RecombeeProductIndexService $service;
    protected Products $product;
    protected Variants $variant;
    protected Warehouses $warehouse;

    public function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        //$app->set('TEST_RECOMBEE_DATABASE_ECOM', getenv('TEST_RECOMBEE_DATABASE_ECOM'));
        //$app->set(ConfigurationEnum::RECOMBEE_API_KEY->value, getenv('TEST_RECOMBEE_API_KEY'));
        //$app->set(ConfigurationEnum::RECOMBEE_REGION->value, getenv('TEST_RECOMBEE_REGION'));

        $this->service = new RecombeeProductIndexService(
            $app,
            getenv('TEST_RECOMBEE_DATABASE_ECOM'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_KEY'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_REGION')
        );

        // Create a published product with variants
        $this->product = Products::factory()->create([
            'is_published' => true,
        ]);
    }

    public function testCreateProductCatalogDatabase(): void
    {
        //$this->markTestSkipped('Requires Recombee API configuration');
        $this->service->createProductCatalogDatabase();

        $indexProduct = $this->service->indexProduct($this->product);

        $this->assertEquals('ok', $indexProduct);
    }

    public function testIndexProductThrowsExceptionForUnpublished(): void
    {
        $unpublishedProduct = Products::factory()->create([
            'is_published' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only published products can be indexed.');

        $this->service->indexProduct($unpublishedProduct);
    }

    public function testIndexProduct(): void
    {
        $indexProduct = $this->service->indexProduct($this->product);

        $this->assertEquals('ok', $indexProduct);
    }

    public function testIndexVariantThrowsExceptionWhenProductUnpublished(): void
    {
        $this->markTestSkipped('Requires Variants');

        /*     $this->product->update(['is_published' => false]);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Only variants of published products can be indexed.');

            $this->service->indexVariant($this->product->variants->first()); */
    }

    public function testIndexVariant(): void
    {
        $this->markTestSkipped('Requires Recombee API configuration');
    }

    public function testUserViewProductInteraction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        // Create VIEW interaction
        $interaction = (new CreateInteraction(
            new Interaction(
                InteractionEnum::VIEW->getValue(),
                $app,
                InteractionEnum::VIEW->getValue()
            )
        ))->execute();

        // Create user interaction for product view
        $userInteractionDto = new UserInteraction(
            $user,
            $interaction,
            (string) $this->product->getId(),
            Products::class,
        );

        $indexProduct = $this->service->indexProduct($this->product);
        $userInteraction = (new CreateUserInteractionAction($userInteractionDto))->execute();

        $recombeeInteractionService = new RecombeeInteractionService(
            $app,
            getenv('TEST_RECOMBEE_DATABASE_ECOM'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_KEY'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_REGION')
        );

        $this->assertNotNull($userInteraction);
        $this->assertEquals($user->getId(), $userInteraction->users_id);
        $this->assertEquals(InteractionEnum::VIEW->getValue(), $interaction->name);
        $this->assertEquals($this->product->getId(), $userInteraction->entity_id);
        $this->assertEquals(Products::class, $userInteraction->entity_namespace);
        $this->assertEquals('ok', $recombeeInteractionService->addUserInteraction($userInteraction));
    }

    public function testGetProductToProductRecommendations(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        // Index the product first
        $this->service->createProductCatalogDatabase();
        $this->service->indexProduct($this->product);

        // Get product-to-product recommendations
        $recommendationService = new RecombeeItemRecommendationService(
            $app,
            getenv('TEST_RECOMBEE_DATABASE_ECOM'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_KEY'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_REGION')
        );

        $recommendations = $recommendationService->getItemRecommendation(
            $user,
            $this->product,
            count: 10,
            scenario: 'product-recommendations'
        );

        $this->assertIsArray($recommendations);
        $this->assertArrayHasKey('recomms', $recommendations);
        $this->assertIsArray($recommendations['recomms']);
    }

    public function testGetUserForYouProductRecommendations(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        // Index the product first
        $this->service->createProductCatalogDatabase();
        $this->service->indexProduct($this->product);

        // Create a user view interaction to generate personalized recommendations
        $interaction = (new CreateInteraction(
            new Interaction(
                InteractionEnum::VIEW->getValue(),
                $app,
                InteractionEnum::VIEW->getValue()
            )
        ))->execute();

        $userInteractionDto = new UserInteraction(
            $user,
            $interaction,
            (string) $this->product->getId(),
            Products::class,
        );

        $userInteraction = (new CreateUserInteractionAction($userInteractionDto))->execute();

        $recombeeInteractionService = new RecombeeInteractionService(
            $app,
            getenv('TEST_RECOMBEE_DATABASE_ECOM'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_KEY'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_REGION')
        );

        $recombeeInteractionService->addUserInteraction($userInteraction);

        $userRecommendationService = new RecombeeUserRecommendationService(
            $app,
            getenv('TEST_RECOMBEE_DATABASE_ECOM'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_KEY'),
            getenv('TEST_RECOMBEE_DATABASE_ECOM_REGION')
        );

        $recommendations = $userRecommendationService->getUserRecommendation(
            $user,
            count: 10,
            scenario: 'product-for-you'
        );

        $this->assertIsArray($recommendations);
        $this->assertArrayHasKey('recomms', $recommendations);
        $this->assertIsArray($recommendations['recomms']);
    }

    public function testGraphQLProductRecommendationsWithProductIntent(): void
    {
        $app = app(Apps::class);

        // Create another product to have multiple products
        $anotherProduct = Products::factory()->create([
            'is_published' => true,
        ]);

        // Index products
        $this->service->createProductCatalogDatabase();
        $this->service->indexProduct($this->product);
        $this->service->indexProduct($anotherProduct);

        // Query product recommendations with product intent
        $query = '
            query($id: ID!, $intent: String!) {
                productRecommendations(id: $id, intent: $intent, first: 10) {
                    data {
                        id
                        name
                        slug
                        is_published
                    }
                    paginatorInfo {
                        total
                        count
                    }
                }
            }
        ';

        $this->graphQL(
            $query,
            [
                'id' => (string) $this->product->getId(),
                'intent' => 'product',
            ]
        )->assertJsonStructure([
            'data' => [
                'productRecommendations' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'is_published',
                        ],
                    ],
                    'paginatorInfo' => [
                        'total',
                        'count',
                    ],
                ],
            ],
        ]);
    }
}
