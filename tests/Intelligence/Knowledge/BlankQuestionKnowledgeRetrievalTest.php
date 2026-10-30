<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Laravel\Ai\Embeddings;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * An MMS with a picture and no text reaches the agent as an empty turn with media attached. The
 * knowledge retrieval embedded that empty question and laravel/ai refused it, failing the whole
 * turn (KANVAS-ECOSYSTEM-6KC). Nothing can be searched for, so the retrieval answers with no hits.
 */
// Flips the shared app's knowledge switch: Redis-backed, visible to every paratest process.
#[Group('serial')]
final class BlankQuestionKnowledgeRetrievalTest extends TestCase
{
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::ENABLED->value, 1);

        Embeddings::fake(function (): never {
            $this->fail('A blank question must never reach the embedder');
        });
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(KnowledgeConfigurationEnum::ENABLED->value);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankQuestions(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \n\t "],
        ];
    }

    #[DataProvider('blankQuestions')]
    public function testABlankQuestionRetrievesNothingWithoutEmbedding(string $question): void
    {
        $retrieval = new KnowledgeRetrieval(
            $this->kanvasApp,
            Companies::first(),
            organizationWide: true,
        );

        $this->assertSame([], $retrieval->retrieve(new UserMessage($question)));
    }
}
