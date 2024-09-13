<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;
use Tests\TestCase;

class LightHouseCacheGenerationTest extends TestCase
{
    /** @test */
    public function testModelCacheGeneration()
    {
        $app = app(Apps::class);
        $user = auth()->user();

        // Create a model instance for testing
        $peopleData = [
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'firstname' => fake()->firstName,
            'middlename' => fake()->lastName,
            'lastname' => fake()->lastName,
            'name' => fake()->name,
            'dob' => fake()->date(),
            'companies_id' => $user->getCurrentCompany()->getId(),
        ];

        $people = People::create($peopleData);
        $people->setCustomFields(['custom_field' => 'value']);
        $people->saveCustomFields();

        $redis = Redis::connection('graph-cache');
        $graphTypeName = $people->getGraphTypeName();
        $relationship = 'custom_fields';
        $items = 25;

        $separator = CacheKeyAndTagsGenerator::SEPARATOR;
        $key = CacheKeyAndTagsGenerator::PREFIX . $separator . $graphTypeName . $separator . $people->getId() . ':' . $relationship . ':first:' . $items;
        $this->assertInstanceOf(LengthAwarePaginator::class, $redis->get($key));
    }
}
