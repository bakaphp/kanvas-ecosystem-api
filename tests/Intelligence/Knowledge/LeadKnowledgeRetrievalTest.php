<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;

class LeadKnowledgeRetrievalTest extends TestCase
{
    /**
     * Shepard Auto, lead 803668: the lot guide scored 0.752 on the agent's documents and still missed the
     * top eight, because the lead's own rows (the question itself at 1.0, five raw tool-call JSON rows)
     * outscored it. Documents take the slots first; the question is never returned to itself.
     */
    public function testTheAgentsDocumentsOutrankTheRecordsOwnRowsAndTheQuestionIsDropped(): void
    {
        $documents = [
            $this->hit('Shepard Auto Group available Lots by Tags: CDJR -> 178 New County Rd', 0.752, 'agent_document'),
            $this->hit('CRITICAL RULE When no matching inventory is found', 0.681, 'agent_document'),
        ];
        $lead = [
            $this->hit('whats your address?', 1.0, 'Lead'),
            $this->hit('{"content":"","tool_results":[{"name":"handoff_lead"}]}', 0.81, 'Lead'),
            $this->hit('just wandering what time do you open?', 0.657, 'Lead'),
        ];

        $ranked = KnowledgeRetrieval::rank(
            $documents,
            $lead,
            3,
            'Whats your address?',
        );

        $this->assertSame(
            [
                '[Company document] Shepard Auto Group available Lots by Tags: CDJR -> 178 New County Rd',
                '[Company document] CRITICAL RULE When no matching inventory is found',
                '[Record history] {"content":"","tool_results":[{"name":"handoff_lead"}]}',
            ],
            array_map(static fn ($document): string => $document->getContent(), $ranked),
            'Labelled the way memory hits are, so the model knows a document from a chat line',
        );
        $this->assertSame('agent_document', $ranked[0]->getSourceType());
    }

    public function testTheRecordsRowsStillFillTheListWhenThereAreFewDocuments(): void
    {
        $ranked = KnowledgeRetrieval::rank(
            [$this->hit('Refund policy', 0.5, 'agent_document')],
            [$this->hit('I want the red Ram', 0.9, 'Lead'), $this->hit('I want the red Ram', 0.8, 'Lead'), $this->hit('Trade-in: 2019 Honda', 0.7, 'Lead')],
            3,
            'which truck did I ask about?',
        );

        $this->assertSame(
            ['[Company document] Refund policy', '[Record history] I want the red Ram', '[Record history] Trade-in: 2019 Honda'],
            array_map(static fn ($document): string => $document->getContent(), $ranked),
            'Documents first, then the record by score, duplicates by content dropped',
        );
    }

    /**
     * @return array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}
     */
    private function hit(string $content, float $score, string $sourceType): array
    {
        return ['content' => $content, 'sourceType' => $sourceType, 'sourceName' => 'test', 'score' => $score, 'metadata' => []];
    }

    public function testRetrievalIsANoOpWithoutTenantContext(): void
    {
        // No app/company in scope: retrieval short-circuits to [] before touching
        // the embedder or the store (the retrieve-docs path is covered live by
        // KnowledgeScopeIsolationTest).
        $documents = new KnowledgeRetrieval(null, null, null)->retrieve(
            new UserMessage('What does our refund policy say?')
        );

        $this->assertSame([], $documents);
    }
}
