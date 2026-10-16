<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\SyncWorkflowStub;
use Tests\TestCase;

final class TagsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm'];

    public function testAddTagToEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $people->addTag('test');

        $this->assertNotEmpty($people->tags()->get());
        $this->assertCount(1, $people->tags()->get());
    }

    public function testAddingANewTagFiresTheAfterAddTagWorkflowOnce(): void
    {
        $app = app(Apps::class);
        $appId = $app->getId();
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $entity = $this->withWorkflowSpy($people);

        $entity->addTag('engaged');
        $entity->addTag('engaged');

        $this->assertCount(1, $entity->firedWorkflows);
        [$event, $params] = $entity->firedWorkflows[0];
        $this->assertSame(WorkflowEnum::AFTER_ADD_TAG->value, $event);
        $this->assertSame('engaged', $params['tag']);
        $this->assertArrayHasKey('tag_id', $params);
        $this->assertSame($appId, $params['app']->getId());
    }

    public function testSyncTagsFiresTheAfterAddTagWorkflowOnlyForTagsTheEntityDidNotHold(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $people->addTag('kept');
        $entity = $this->withWorkflowSpy($people);

        $entity->syncTags(['kept', 'added']);

        $this->assertSame(['added'], array_map(fn (array $fired): string => $fired[1]['tag'], $entity->firedWorkflows));
        $this->assertEqualsCanonicalizing(['kept', 'added'], $entity->tags()->pluck('name')->all());
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

    /**
     * Mockery::mock($object) is a proxy, so the trait's own $this->fireWorkflow() reaches the real
     * object and never the expectation; a Mockery class partial of a model trips its re-entrant boot.
     */
    private function withWorkflowSpy(People $people): People
    {
        $entity = new class () extends People {
            public array $firedWorkflows = [];

            public function fireWorkflow(string $event, bool $async = true, array $params = []): ?SyncWorkflowStub
            {
                $this->firedWorkflows[] = [$event, $params];

                return null;
            }

            public function getMorphClass(): string
            {
                return new People()->getMorphClass();
            }
        };
        $entity->setRawAttributes($people->getAttributes(), true);
        $entity->exists = true;

        return $entity;
    }
}
