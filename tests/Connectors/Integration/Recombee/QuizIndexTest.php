<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Recombee;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Giftea\Services\QuizService;
use Kanvas\Connectors\Giftea\Services\RecombeeItemService;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeInteractionService;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class QuizIndexTest extends TestCase
{
    protected ?Message $quizResponses = null;
    protected array $products = [];

    public function setUp(): void
    {
        parent::setUp();
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::RECOMBEE_DATABASE->value, env('TEST_RECOMBEE_DATABASE'));
        $app->set(ConfigurationEnum::RECOMBEE_API_KEY->value, env('TEST_RECOMBEE_API_KEY'));
        $app->set(ConfigurationEnum::RECOMBEE_REGION->value, env('TEST_RECOMBEE_REGION'));
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageData = [
            'recipient' => 'pareja',
            'age' => '26-35',
            'occasion' => 'aniversario',
            'interests' => ['música', 'tecnología'],
            'personality' => 'practico',
            'budget' => '50-100'
        ];

        $messageType = MessageType::factory()->create();

        $this->quizResponses = Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'message' => $messageData,
            'message_types_id' => $messageType->getId(),
            'total_liked' => 0,
            'users_id' => $user->getId(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        // Simulate product catalog
        $this->products = [
            [
                'id' => 'product-1',
                'name' => 'Modern Office Chair',
                'category' => 'furniture',
                'style' => 'modern',
                'color' => 'blue',
                'price_range' => 'mid',
                'suitable_for' => ['office', 'home'],
                'companies_id' => $company->getId(),
            ],
            [
                'id' => 'product-2',
                'name' => 'Wireless Headphones',
                'category' => 'electronics',
                'style' => 'modern',
                'color' => 'black',
                'price_range' => 'mid',
                'suitable_for' => ['office', 'travel'],
                'companies_id' => $company->getId(),
            ],
            [
                'id' => 'product-3',
                'name' => 'Standing Desk',
                'category' => 'furniture',
                'style' => 'modern',
                'color' => 'white',
                'price_range' => 'high',
                'suitable_for' => ['office'],
                'companies_id' => $company->getId(),
            ],
        ];
    }

    public function indexProducts(RecombeeItemService $itemService): void
    {
        
        $itemService->createProductDatabase();

        // Index all products
        foreach ($this->products as $product) {
            $itemService->addItem($product['id'], [
                'name' => $product['name'],
                'category' => $product['category'],
                'style' => $product['style'],
                'color' => $product['color'],
                'price_range' => $product['price_range'],
                'suitable_for' => implode(',', $product['suitable_for']),
            ]);
        }
    }

    public function testIndexProducts(): void
    {
        $app = app(Apps::class);

        $itemService = new RecombeeItemService(
            $app,
            env('TEST_RECOMBEE_DATABASE'),
            env('TEST_RECOMBEE_API_KEY'),
            env('TEST_RECOMBEE_REGION')
        );

        $itemService->createProductDatabase();

        // Index all products
        foreach ($this->products as $product) {
            $result = $itemService->addItem($product['id'], [
                'name' => $product['name'],
                'category' => $product['category'],
                'style' => $product['style'],
                'color' => $product['color'],
                'price_range' => $product['price_range'],
                'suitable_for' => implode(',', $product['suitable_for']),
            ]);

            $this->assertEquals('ok', $result);
        }
    }

    public function testGetRecommendationsWithQuizFilters(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $itemService = new RecombeeItemService(
            $app,
            env('TEST_RECOMBEE_DATABASE'),
            env('TEST_RECOMBEE_API_KEY'),
            env('TEST_RECOMBEE_REGION')
        );

        $QuizService = new QuizService(
            recombeeService: $itemService
        );

       $this->indexProducts($itemService);      

       $recommendations = $QuizService->processQuizSubmission(
            $this->quizResponses, 
            (string) $user->getId()
        );

        $this->assertIsArray($recommendations);
    }
}
