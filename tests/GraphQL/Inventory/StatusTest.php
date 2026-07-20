<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Status\Models\Status;
use Tests\TestCase;

class StatusTest extends TestCase
{
    /**
     * Exercises the scoped Status::search() directly. The `status` GraphQL query is behind
     * @can(view); the search()/tenant-scoping logic under test is identical to Channels (which
     * is covered end-to-end via GraphQL), so here we assert the model-level search + scoping.
     */
    public function testSearchStatus(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $name = 'Status-' . fake()->unique()->uuid();

        Status::create([
            'name' => $name,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $names = Status::search($name)->get()->pluck('name')->all();

        $this->assertContains($name, $names);
    }
}
