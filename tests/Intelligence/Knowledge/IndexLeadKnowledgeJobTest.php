<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\RAG\Jobs\IndexKnowledgeJob;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

class IndexLeadKnowledgeJobTest extends TestCase
{
    public function testJobLoadsAndUsesATenantScopedUniqueKey(): void
    {
        $job = $this->makeJob(appId: 11);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(Lead::class . ':11:22:33', $job->uniqueId());
    }

    public function testProviderRateLimitReleasesTheJobInsteadOfFailingIt(): void
    {
        $appId = random_int(1_000_000, 9_999_999);
        $job = $this->makeJob($appId)->withFakeQueueInteractions();

        try {
            $job->middleware()[0]->handle(
                $job,
                fn () => throw RateLimitedException::forProvider("knowledge_gemini_app_{$appId}", 429)
            );
        } finally {
            RateLimiter::clear('knowledge-embeddings:app:' . $appId);
        }

        $job->assertReleased(delay: 120);
        $job->assertNotFailed();
        $this->assertNotNull($job->retryUntil());
    }

    public function testOtherErrorsStillThrowSoMaxExceptionsCanFailTheJob(): void
    {
        $job = $this->makeJob(random_int(1_000_000, 9_999_999))->withFakeQueueInteractions();

        $this->expectException(RuntimeException::class);

        $job->middleware()[0]->handle($job, fn () => throw new RuntimeException('boom'));
    }

    private function makeJob(int $appId): IndexKnowledgeJob
    {
        $lead = new Lead(['apps_id' => $appId, 'companies_id' => 22]);
        $lead->id = 33;

        return new IndexKnowledgeJob(KnowledgeEntity::fromModel($lead));
    }
}
