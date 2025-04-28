<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class TagsTest extends TestCase
{
    public function testAddTagToEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $people->addTag('test');

        $this->assertNotEmpty($people->tags()->get());
        $this->assertCount(1, $people->tags()->get());
    }

    public function testAddMultipleTagsToEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $people->addTags(['test', 'test2']);

        $this->assertNotEmpty($people->tags()->get());
        $this->assertCount(2, $people->tags()->get());
    }

    public function testRemoveTagFromEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $people->addTag('test');
        $people->addTag('test2');

        $this->assertCount(2, $people->tags()->get());

        $people->removeTag('test');

        $this->assertCount(1, $people->tags()->get());
    }

    public function testRemoveTagsFromEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $people->addTag('test');
        $people->addTag('test2');

        $this->assertCount(2, $people->tags()->get());

        $people->removeTags(['test', 'test2']);

        $this->assertCount(0, $people->tags()->get());
    }

    public function testSyncTagsFromEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $people->addTag('test');
        $people->addTag('test2');

        $this->assertCount(2, $people->tags()->get());

        $people->syncTags(['test3', 'test4']);

        $this->assertCount(2, $people->tags()->get());
    }
}
