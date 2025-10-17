<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Recombee;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Giftea\Handlers\GifteaHandler;
use Kanvas\Connectors\Giftea\Services\QuizService;
use Kanvas\Connectors\Giftea\Services\RecombeeItemService;
use Kanvas\Connectors\Giftea\Workflows\PushQuizToItemActivity;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

class QuizIndexTest extends TestCase
{
    use HasIntegrationCompany;
    protected ?Message $quizResponses = null;
    protected array $products = [];
    protected ?Users $user = null;
    protected ?Companies $company = null;
    protected ?Apps $apps = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->apps->set(ConfigurationEnum::RECOMBEE_DATABASE->value, env('TEST_RECOMBEE_DATABASE'));
        $this->apps->set(ConfigurationEnum::RECOMBEE_API_KEY->value, env('TEST_RECOMBEE_API_KEY'));
        $this->apps->set(ConfigurationEnum::RECOMBEE_REGION->value, env('TEST_RECOMBEE_REGION'));
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $company = $this->company;

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
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'message' => $messageData,
            'message_types_id' => $messageType->getId(),
            'total_liked' => 0,
            'users_id' => $this->user->getId(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->products = [
            [
                'id' => 'product-1',
                'name' => 'Auriculares Bluetooth Premium',
                'category' => 'tecnología',
                'price' => 75,
                'price_range' => '50-100',
                'age_group' => '26-35',
                'relation_type' => 'pareja',
                'interests' => ['música', 'tecnología'],
                'gift_type' => 'util',
                'occasion' => 'aniversario',
                'gift_style' => 'practico',
                'image_url' => 'https://example.com/headphones.jpg',
                'companies_id' => $company->getId(),
            ],
            [
                'id' => 'product-2',
                'name' => 'Smartwatch Deportivo',
                'category' => 'tecnología',
                'price' => 120,
                'price_range' => '100-200',
                'age_group' => '26-35',
                'relation_type' => 'pareja',
                'interests' => ['tecnología', 'deportes'],
                'gift_type' => 'util',
                'occasion' => 'cumpleaños',
                'gift_style' => 'practico',
                'image_url' => 'https://example.com/smartwatch.jpg',
                'companies_id' => $company->getId(),
            ],
            [
                'id' => 'product-3',
                'name' => 'Vinilo Edición Especial The Beatles',
                'category' => 'música',
                'price' => 45,
                'price_range' => '25-50',
                'age_group' => '26-35',
                'relation_type' => 'pareja',
                'interests' => ['música', 'arte'],
                'gift_type' => 'divertido',
                'occasion' => 'aniversario',
                'gift_style' => 'unico',
                'image_url' => 'https://example.com/vinyl.jpg',
                'companies_id' => $company->getId(),
            ],
            [
                'id' => 'product-4',
                'name' => 'Collar de Oro con Diamante',
                'category' => 'joyería',
                'price' => 350,
                'price_range' => '200+',
                'age_group' => '26-35',
                'relation_type' => 'pareja',
                'interests' => ['moda'],
                'gift_type' => 'lujo',
                'occasion' => 'aniversario',
                'gift_style' => 'elegante',
                'image_url' => 'https://example.com/necklace.jpg',
                'companies_id' => $company->getId(),
            ],
            [
                'id' => 'product-5',
                'name' => 'Set de Herramientas para Hogar',
                'category' => 'hogar',
                'price' => 55,
                'price_range' => '50-100',
                'age_group' => '36-50',
                'relation_type' => 'familiar',
                'interests' => ['bricolaje'],
                'gift_type' => 'util',
                'occasion' => 'cumpleaños',
                'gift_style' => 'practico',
                'image_url' => 'https://example.com/tools.jpg',
                'companies_id' => $company->getId(),
            ],
        ];
    }

    public function indexProducts(RecombeeItemService $itemService): void
    {
        $itemService->createProductDatabase();

        foreach ($this->products as $product) {
            $itemService->addItem($product['id'], [
                'name' => $product['name'],
                'category' => $product['category'],
                'price' => $product['price'],
                'price_range' => $product['price_range'],
                'age_group' => $product['age_group'],
                'relation_type' => $product['relation_type'],
                'interests' => $product['interests'],
                'gift_type' => $product['gift_type'],
                'occasion' => $product['occasion'],
                'gift_style' => $product['gift_style'],
                'image_url' => $product['image_url'],
                'companies_id' => $product['companies_id'],
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

        foreach ($this->products as $product) {
            $result = $itemService->addItem($product['id'], [
                'name' => $product['name'],
                'category' => $product['category'],
                'price' => $product['price'],
                'price_range' => $product['price_range'],
                'age_group' => $product['age_group'],
                'relation_type' => $product['relation_type'],
                'interests' => $product['interests'],
                'gift_type' => $product['gift_type'],
                'occasion' => $product['occasion'],
                'gift_style' => $product['gift_style'],
                'image_url' => $product['image_url'],
                'companies_id' => $product['companies_id'],
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

    public function testQuizActivity(): void
    {
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::GIFTEA,
            GifteaHandler::class,
            $this->company,
            $this->user
        );

        $itemService = new RecombeeItemService(
            $this->apps,
            env('TEST_RECOMBEE_DATABASE'),
            env('TEST_RECOMBEE_API_KEY'),
            env('TEST_RECOMBEE_REGION')
        );

        $QuizService = new QuizService(
            recombeeService: $itemService
        );

        $this->indexProducts($itemService);

        $activity = new PushQuizToItemActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute(
            $this->quizResponses,
            $this->apps,
            [
                'message_type_id' => $this->quizResponses->message_types_id,
                'service' => $itemService
            ]
        );

        $newMessage = Message::getById($this->quizResponses->getId(), $this->apps);
        $this->assertNotEmpty($newMessage->message['recoms'] ?? null);
    }
}
