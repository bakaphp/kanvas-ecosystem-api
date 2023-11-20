<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Search\IndexInMeiliSearchJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class MeiliSearchTest extends TestCase
{
    /** @test */
    public function testModelIndexMeiliSearch()
    {
        // Create a model instance for testing
        $lead = Lead::first() ?? Lead::factory()->create();

        Queue::fake();
        $indexName = 'global_app_custom_index';
        IndexInMeiliSearchJob::dispatch($indexName, $lead);

        Queue::assertPushed(IndexInMeiliSearchJob::class, function ($job) use ($indexName, $lead) {
            return $job->indexName == $indexName && $job->model->id == $lead->id;
        });
    }
}
