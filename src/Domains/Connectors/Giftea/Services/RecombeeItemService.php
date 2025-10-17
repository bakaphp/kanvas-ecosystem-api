<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Giftea\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Inventory\Variants\Models\Variants;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Exceptions\ApiException;
use Recombee\RecommApi\Requests\AddItemProperty;
use Recombee\RecommApi\Requests\AddUserProperty;
use Recombee\RecommApi\Requests\ListItemProperties;
use Recombee\RecommApi\Requests\ListUserProperties;
use Recombee\RecommApi\Requests\RecommendItemsToUser;
use Recombee\RecommApi\Requests\SetItemValues;
use Recombee\RecommApi\Requests\SetUserValues;

class RecombeeItemService
{
    protected RecommApiClient $client;

    public function __construct(
        protected AppInterface $app,
        ?string $recombeeDatabase = null,
        ?string $recombeeApiKey = null,
        ?string $recombeeRegion = null
    ) {
        $this->client = (new Client(
            $app,
            $recombeeDatabase,
            $recombeeApiKey,
            $recombeeRegion
        ))->getClient();
    }

     public function createProductDatabase(): void
    {
        $properties = [
            'name' => 'string',
            'category' => 'string',
            'suitable_for' => 'string',
            'style' => 'string',
            'color' => 'string',
            'categories' => 'set',
            'image_url' => 'string',
            'type' => 'string',
            'companies_id' => 'int',
            'price' => 'double',
            'price_range' => 'string',
            'age_group' =>	'string', 	//"adult", "child", "baby"
            'relation_type' =>	'string', //	"pareja", "familiar"
            'style' =>	'string',	// "creativo", "tecnologico", "sofisticado"
            'gender_target' =>	'string', //	"neutro", "hombre", "mujer"
            'interests'	=> 'set', 	// ["musica", "arte", "viajes"]
            'gift_type'	=> 'string', //"util", "divertido", "lujo"
            'occasion'	=> 'string',	//"cumpleanos", "navidad", "graduacion"
            'delivery_urgency'	=> 'string',	//"2dias", "semana", "no_urgente"
            'gift_style'	=> 'string', //	"unico", "practico", "impresionante", "divertido"
        ];
        $existingProperties = $this->client->send(new ListItemProperties());
        $existingPropertyNames = array_column($existingProperties, 'name');

        foreach ($properties as $property => $type) {
            if (! in_array($property, $existingPropertyNames)) {
                $this->addItemProperty($property, $type);
            }
        }
    }

    public function createUsersDatabase(): void
    {
        $properties = [
            'firstname' => 'string',
            'lastname' => 'string',
            'email' => 'string',
            'displayname' => 'string',
            'liked_categories' => 'set',
            'style_preference' => 'string',
            'budget_range' => 'string',
            'primary_category' => 'string',
            'quiz_completed' => 'boolean',
            'recipient_type' => 'string',
            'target_age' => 'string',
            'occasion' => 'string',
            'interests' => 'set',
            'personality' => 'string',
            'budget' => 'string',
        ];
        $existingProperties = $this->client->send(new ListUserProperties());
        $existingPropertyNames = array_column($existingProperties, 'name');

        foreach ($properties as $property => $type) {
            if (! in_array($property, $existingPropertyNames)) {
                // Property does not exist, add it
                $this->client->send(new AddUserProperty($property, $type));
            }
        }
    }

    public function indexProductVariant(Variants $variant): mixed
    {
        try {
            $price = $variant->getPriceInfoFromDefaultChannel()->price;
        } catch (Exception $e) {
            return null;
        }

        $priceRange = $price < 50 ? '0-50' : ($price < 100 ? '50-100' : ($price < 200 ? '100-200' : '200+'));

        $data = [
            'name' => $variant->name,
            'category' => $variant->product->categories?->first()?->name,
            'price' => $price,
            'price_range' => $priceRange,
            'age_group' => '26-35',
            'relation_type' => $variant->get('relation_type') ?? null,
            'interests' => $variant->get('interests') ?? null,
            'gift_type' => $variant->get('gift_type') ?? null,
            'occasion' => $variant->get('occasion') ?? null,
            'gift_style' => $variant->get('gift_style') ?? null,
            'image_url' => $variant->getFiles()->first()?->url ?? null,
            'companies_id' => $variant->company->getId(),
            'title' => $variant->name,
            'description' => $variant->description,
            'users_id' => $variant->users_id,
            'total_like' => $variant->total_liked,
            'total_dislike' => $variant->total_disliked,
            'total_share' => $variant->total_shared,
            'total_save' => $variant->total_saved,
            'total_purchase' => $variant->total_purchased,
            'created_at' => (int) strtotime($variant->created_at->toDateTimeString()),
            'updated_at' => (int) strtotime($variant->updated_at->toDateTimeString()),
            'is_premium' => $variant->is_premium,
            'categories' => $variant->product->categories->pluck('name')->toArray(),
            'interests' => $variant->product->categories->pluck('name')->toArray(),
            'type' => $variant->product->productsType->name ?? null,
        ];
        $request = new SetItemValues(
            $variant->getId(),
            $data,
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }
  
    public function addItem(string $itemId, array $properties = []): mixed
    {
        $request = new SetItemValues(
            $itemId,
            $properties,
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    public function setUserProperties(string|int $userId, array $properties = []): mixed
    {
        $this->createUsersDatabase($properties);

        $request = new SetUserValues(
            $userId,
            $properties,
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    public function addItemProperty(string $propertyName, string $type): mixed
    {
        $request = new AddItemProperty($propertyName, $type);

        return $this->client->send($request);
    }

    public function getRecommendations(string $userId, array $filters = [], int $limit = 20): array
    {
        try {
            $options = [
                'scenario' => 'gift-recommendation',
                'returnProperties' => true,
                'cascadeCreate' => true,
                'diversity' => 0.5,
            ];

            if (!empty($filters)) {
                $boosterString = $this->buildBooster($filters);
                if ($boosterString) {
                    $options['booster'] = $boosterString;
                }
            }

            $request = new RecommendItemsToUser($userId, $limit, $options);
            $response = $this->client->send($request);

            print_r(["response" => $response ]);

            return $response['recomms'] ?? [];

        } catch (ApiException $e) {
            Log::error('Recombee recommendation error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function buildBooster(array $filters): ?string
    {
        $boosters = [];

        if (!empty($filters['categories'])) {
            $categoryBoosts = [];
            foreach ($filters['categories'] as $cat) {
                $categoryBoosts[] = "'category' == \"{$cat}\"";
            }
            if (!empty($categoryBoosts)) {
                $boosters[] = "(if (" . implode(' or ', $categoryBoosts) . ") then 2.0 else 1.0)";
            }
        }

        if (isset($filters['occasion'])) {
            $boosters[] = "(if 'occasion' == \"{$filters['occasion']}\" then 1.5 else 1.0)";
        }

        if (isset($filters['ageRange'])) {
            $boosters[] = "(if 'age_group' == \"{$filters['ageRange']}\" then 1.3 else 1.0)";
        }

        if (isset($filters['personality'])) {
            $boosters[] = "(if 'gift_style' == \"{$filters['personality']}\" then 1.2 else 1.0)";
        }

        return !empty($boosters) ? implode(' * ', $boosters) : null;
    }
}
