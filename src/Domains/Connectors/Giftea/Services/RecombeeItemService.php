<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Giftea\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Recombee\Client;
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
        string $recombeeRegion = 'ca-east'
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
            'price' => 'int',
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
                'scenario' => config('recombee.scenarios.gift_finder'),
                'returnProperties' => true,
                'cascadeCreate' => true,
                'includedProperties' => [
                    'name', 'category', 'price_range', 
                    'image_url', 'description', 'age_group',
                    'occasion', 'interests', 'gift_type',
                ],
                'diversity' => 0.3
            ];

            if (!empty($filters)) {
                $filterString = $this->buildRecombeeFilter($filters);
                if ($filterString) {
                    $options['filter'] = $filterString;
                }

                $boosterString = $this->buildBooster($filters);
                if ($boosterString) {
                    $options['booster'] = $boosterString;
                }
            }

            $request = new RecommendItemsToUser($userId, $limit, $options);
            $response = $this->client->send($request);

            return $response['recomms'] ?? [];

        } catch (ApiException $e) {
            Log::error('Recombee recommendation error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function buildRecombeeFilter(array $filters): ?string
    {
        $conditions = [];

        // Filtro de precio (HARD FILTER)
        if (isset($filters['priceRange'])) {
            [$min, $max] = $filters['priceRange'];
            $conditions[] = "'price' >= {$min} and 'price' <= {$max}";
        }

        // Filtro de categorías (OR)
        if (!empty($filters['categories'])) {
            $categoryConditions = array_map(
                fn($cat) => "'category' == \"{$cat}\"",
                $filters['categories']
            );
            $conditions[] = '(' . implode(' or ', $categoryConditions) . ')';
        }

        // Filtro de ocasión
        if (isset($filters['occasion'])) {
            $conditions[] = "\"{$filters['occasion']}\" in 'occasion'";
        }

        // Filtro de rango de edad
        if (isset($filters['ageRange'])) {
            $conditions[] = "\"{$filters['ageRange']}\" in 'age_group'";
        }

        return !empty($conditions) ? implode(' and ', $conditions) : null;
    }

    private function buildBooster(array $filters): ?string
    {
        $boosters = [];

        // Boost por tags preferidos (intereses del quiz)
        // Si un producto tiene estos tags, su score aumenta 50%
        if (!empty($filters['preferredTags'])) {
            foreach ($filters['preferredTags'] as $tag) {
                $boosters[] = "if \"{$tag}\" in 'tags' then 1.5 else 1";
            }
        }

        // Boost por personalidad
        // Si el producto coincide con la personalidad, aumenta 30%
        if (isset($filters['personality'])) {
            $boosters[] = "if \"{$filters['personality']}\" in 'personality' then 1.3 else 1";
        }

        // Boost por productos populares (opcional)
        // Puedes agregar un campo 'popularity_score' a tus productos
        // $boosters[] = "'popularity_score'";

        return !empty($boosters) ? implode(' * ', $boosters) : null;
    }
}
