<?php

declare(strict_types=1);

namespace Tests\Evaluations\LeadMemory;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Summary\LeadSummaryAgent;
use Kanvas\Guild\Leads\Summary\LeadSummaryBatch;
use Kanvas\Guild\Leads\Summary\LeadSummaryFold;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Kanvas\Guild\Leads\Summary\LeadSummaryUpdateValidator;
use Kanvas\Guild\Leads\Summary\Output\LeadSummaryUpdate;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use NeuronAI\Providers\AIProviderInterface;

/**
 * What the shape checks cannot prove: that the rolling summary keeps a commitment it saw, closes an item
 * only when a message really closes it, and invents nothing. Runs the production fold (prompt, validator,
 * apply) over each transcript in chunks, the way a long lead is covered, against the real model.
 *
 *   GEMINI_API_KEY=... vendor/bin/neuron evaluation tests/Evaluations/LeadMemory
 *
 * The dataset holds synthetic scenarios for the four D11 categories. Before rollout beyond the first
 * tenant, add anonymised real transcripts in the same shape (datasets/README.md).
 */
class LeadSummaryEvaluator extends BaseEvaluator
{
    private const int MESSAGES_PER_CHUNK = 4;

    private AIProviderInterface $provider;

    private Agent $judge;

    public function setUp(): void
    {
        self::bootApplication();

        $this->provider = new KanvasGemini(
            key: (string) config('ai.providers.gemini.key'),
            model: (string) (getenv('LEAD_SUMMARY_EVAL_MODEL') ?: 'gemini-2.5-flash'),
        );
        $this->judge = Agent::make()->setAiProvider($this->provider);
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__ . '/datasets/lead-summary.json');
    }

    /**
     * @param array<string, mixed> $datasetItem
     * @return array{state: LeadSummaryState, rejected: int}
     */
    public function run(array $datasetItem): array
    {
        $state = new LeadSummaryState();
        $rejected = 0;

        foreach (array_chunk($datasetItem['messages'], self::MESSAGES_PER_CHUNK) as $chunk) {
            $batch = new LeadSummaryBatch(array_column(array_map(
                static fn (array $message): array => [
                    'id' => $message['id'],
                    'date' => $message['date'],
                    'line' => LeadSummaryBatch::line(
                        (int) $message['id'],
                        $message['date'],
                        $message['speaker'],
                        $message['text'],
                    ),
                ],
                $chunk,
            ), null, 'id'));

            /** @var LeadSummaryUpdate $update */
            $update = $this->metered(LeadSummaryAgent::make()->setAiProvider($this->provider))
                ->setThreadId('lead-summary-eval-' . Str::uuid7())
                ->structured(
                    new UserMessage(LeadSummaryFold::prompt(
                        $datasetItem['title'],
                        $state,
                        $batch,
                        context: (string) ($datasetItem['context'] ?? ''),
                    )),
                    LeadSummaryUpdate::class,
                );

            if (new LeadSummaryUpdateValidator($state, $batch)->violations($update) !== []) {
                $rejected++;

                continue;
            }

            $state = LeadSummaryFold::apply($state, $update, $batch);
        }

        return ['state' => $state, 'rejected' => $rejected];
    }

    /**
     * @param array{state: LeadSummaryState, rejected: int} $output
     * @param array<string, mixed> $datasetItem
     */
    public function evaluate(mixed $output, array $datasetItem): void
    {
        $transcript = implode("\n", array_map(
            static fn (array $message): string => "[{$message['date']}] {$message['speaker']}: {$message['text']}",
            $datasetItem['messages'],
        ));
        $openItems = Str::lower(implode("\n", array_column($output['state']->openItems(), 'text')));

        if ($datasetItem['expect_open'] !== []) {
            $this->assert(new StringContainsAll(array_map(Str::lower(...), $datasetItem['expect_open'])), $openItems, 'commitment recall');
        }

        $this->assert(
            new AgentJudge(
                $this->judge,
                'Every item listed is still in force at the end of the transcript: nothing that was fulfilled, cancelled or corrected later is listed, and nothing still promised or instructed is missing.',
                threshold: 0.7,
                reference: $transcript,
            ),
            "Open items:\n" . ($openItems === '' ? '(none)' : $openItems),
            'items resolved only when resolved',
        );

        $this->assert(
            new AgentJudge(
                $this->judge,
                'Every fact in the summary is stated in the transcript: no invented price, date, appointment, vehicle or trade-in, and later corrections win over earlier statements.',
                threshold: 0.8,
                reference: $transcript,
            ),
            $output['state']->render(),
            'sections agree with the transcript',
        );
    }

    /**
     * `vendor/bin/neuron` runs outside artisan, so the app that holds the config and the container the
     * providers log through is booted here, once per process.
     */
    private static function bootApplication(): void
    {
        if (Container::getInstance()->bound('config')) {
            return;
        }

        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    public function cacheDependencies(): array
    {
        return [LeadSummaryAgent::class, LeadSummaryFold::class, __DIR__ . '/datasets/lead-summary.json'];
    }
}
