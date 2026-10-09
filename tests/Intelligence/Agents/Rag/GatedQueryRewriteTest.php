<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Rag;

use Kanvas\Intelligence\Agents\Neuron\RAG\PreProcessors\GatedQueryRewrite;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use RuntimeException;
use Tests\TestCaseUnit;

/**
 * The rewrite is a model call the customer waits on, so it runs only where it can change the search:
 * a terse message or a long one mixing several asks. A plain sentence searches as itself.
 */
final class GatedQueryRewriteTest extends TestCaseUnit
{
    public function testAPlainSentenceSkipsTheRewrite(): void
    {
        $rewriter = $this->rewriter();
        $question = new UserMessage('Yes and I am qualified to get this vehicle with no down payment is that right?');

        $result = new GatedQueryRewrite($rewriter)->process($question);

        $this->assertSame($question, $result);
        $this->assertSame(0, $rewriter->calls);
    }

    public function testATerseMessageIsRewritten(): void
    {
        $rewriter = $this->rewriter();

        $result = new GatedQueryRewrite($rewriter)->process(new UserMessage('price?'));

        $this->assertSame('rewritten query', $result->getContent());
        $this->assertSame(1, $rewriter->calls);
    }

    public function testALongMessageMixingSeveralAsksIsRewritten(): void
    {
        $rewriter = $this->rewriter();
        $long = implode(' ', array_fill(0, GatedQueryRewrite::MESSY_MIN_WORDS, 'word'));

        new GatedQueryRewrite($rewriter)->process(new UserMessage($long));

        $this->assertSame(1, $rewriter->calls);
    }

    public function testAFailedRewriteSearchesWithTheMessageAsWritten(): void
    {
        $question = new UserMessage('price?');

        $result = new GatedQueryRewrite($this->rewriter(throws: true))->process($question);

        $this->assertSame($question, $result);
    }

    private function rewriter(bool $throws = false): PreProcessorInterface
    {
        return new class ($throws) implements PreProcessorInterface {
            public int $calls = 0;

            public function __construct(
                private readonly bool $throws,
            ) {
            }

            public function process(Message $question): Message
            {
                $this->calls++;

                if ($this->throws) {
                    throw new RuntimeException('provider down');
                }

                return new UserMessage('rewritten query');
            }
        };
    }
}
