<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Attributes recommendation latency before/after a change to the agent's output
 * schema. Completion tokens are the number that matters: the agent used to
 * re-emit every product field into its structured output, and generation time
 * scales with that count — so a large drop there is the whole latency win.
 */
class BenchmarkProductRecommendationCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:benchmark-product-recommendation
                            {app_id : App the agent belongs to}
                            {agent_id : Agent record to run}
                            {--company_id= : Company context, defaults to the agent company}
                            {--query=* : Query to run; repeat the flag for several, omit for the built-in set}
                            {--runs=1 : Times to repeat each query}';

    /**
     * @var string
     */
    protected $description = 'Time the inventory recommendation agent and report tokens + tool calls per query';

    private const array DEFAULT_QUERIES = [
        'Recomiendame un regalo para una mujer de 32 años, creativa y amante del diseño y el café',
        'algo para mi hermano de 25 que le gustan las cosas caras',
        'un regalo para mi mamá en su cumpleaños, menos de $50',
        'necesito un detalle para mi novia, algo romántico',
        'a gift for a coworker who likes coffee, under $30',
    ];

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Agent $agent */
        $agent = Agent::getById((int) $this->argument('agent_id'), $app);

        $companyId = $this->option('company_id');
        $company = $companyId !== null
            ? Companies::getById((int) $companyId)
            : $agent->company;

        $handlerClass = $agent->type?->handler;

        if ($handlerClass === null || ! class_exists($handlerClass)) {
            $this->error("Agent {$agent->getId()} has no valid handler on its agent_type.");

            return self::FAILURE;
        }

        $queries = $this->option('query') ?: self::DEFAULT_QUERIES;
        $runs = max(1, (int) $this->option('runs'));

        $rows = [];
        $durations = [];

        foreach ($queries as $query) {
            for ($run = 1; $run <= $runs; $run++) {
                $rows[] = $this->benchmarkOnce(
                    $handlerClass,
                    $agent,
                    $app,
                    $company,
                    $query,
                    $durations,
                );
            }
        }

        $this->table(
            ['Query', 'Seconds', 'Tool calls', 'Prompt tok', 'Completion tok', 'Result'],
            $rows,
        );

        $this->summarize($durations);

        return self::SUCCESS;
    }

    /**
     * @param array<int, float> $durations collected across runs for the summary
     *
     * @return array<int, string>
     */
    private function benchmarkOnce(
        string $handlerClass,
        Agent $agent,
        Apps $app,
        Companies $company,
        string $query,
        array &$durations,
    ): array {
        $handler = new $handlerClass();

        if (! $handler instanceof KanvasLaravelAgent) {
            return [$this->truncate($query), '-', '-', '-', '-', 'not a laravel agent'];
        }

        $handler->setConfiguration(
            agent: $agent,
            app: $app,
            company: $company,
        );

        $start = microtime(true);

        try {
            $response = $handler->promptWithConfig($query);
        } catch (Throwable $e) {
            return [$this->truncate($query), sprintf('%.2f', microtime(true) - $start), '-', '-', '-', 'ERROR: ' . $this->truncate($e->getMessage(), 40)];
        }

        $seconds = microtime(true) - $start;
        $durations[] = $seconds;

        return [
            $this->truncate($query),
            sprintf('%.2f', $seconds),
            (string) $response->toolCalls->count(),
            (string) $response->usage->promptTokens,
            (string) $response->usage->completionTokens,
            $this->describeResult($response->toArray()),
        ];
    }

    private function describeResult(array $structured): string
    {
        $recommendations = $structured['recommendations'] ?? null;

        return is_array($recommendations)
            ? count($recommendations) . ' recs'
            : 'no structured output';
    }

    /**
     * @param array<int, float> $durations
     */
    private function summarize(array $durations): void
    {
        if ($durations === []) {
            return;
        }

        sort($durations);
        $count = count($durations);

        $this->newLine();
        $this->line(sprintf(
            'runs=%d  min=%.2fs  median=%.2fs  max=%.2fs',
            $count,
            $durations[0],
            $durations[intdiv($count, 2)],
            $durations[$count - 1],
        ));
    }

    private function truncate(string $value, int $length = 55): string
    {
        return mb_strimwidth($value, 0, $length, '…');
    }
}
