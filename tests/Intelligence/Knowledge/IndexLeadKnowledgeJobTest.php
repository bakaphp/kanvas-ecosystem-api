<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\RAG\IndexKnowledgeJob;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Tests\TestCase;

class IndexLeadKnowledgeJobTest extends TestCase
{
    public function testJobLoadsAndUsesATenantScopedUniqueKey(): void
    {
        $lead = new Lead(['apps_id' => 11, 'companies_id' => 22]);
        $lead->id = 33;

        $job = new IndexKnowledgeJob(KnowledgeEntity::fromModel($lead));

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(Lead::class . ':11:22:33', $job->uniqueId());
    }
}
