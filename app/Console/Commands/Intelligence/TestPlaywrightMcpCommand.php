<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Laravel\Mcp\Client\ClientManager;
use Throwable;

class TestPlaywrightMcpCommand extends Command
{
    protected $signature = 'intelligence:test-playwright-mcp {--url=https://example.com}';

    protected $description = 'Verify Playwright MCP tools and remote browser control without an LLM';

    public function handle(ClientManager $clients): int
    {
        // build() deliberately creates a new MCP session for each agent/job execution.
        $client = $clients->build('playwright');

        try {
            $client->connect();
            $tools = $client->tools();
            $names = $tools->keys()->all();

            $this->info(sprintf('MCP tools (%d): %s', count($names), implode(', ', $names)));

            foreach (['browser_navigate', 'browser_snapshot'] as $requiredTool) {
                if (! $tools->has($requiredTool)) {
                    $this->error("Required tool is missing: {$requiredTool}");

                    return self::FAILURE;
                }
            }

            $navigation = $client->callTool('browser_navigate', [
                'url' => (string) $this->option('url'),
            ]);

            if ($navigation->isError) {
                $this->error('browser_navigate failed: ' . $navigation->text());

                return self::FAILURE;
            }

            $snapshot = $client->callTool('browser_snapshot');
            $snapshotText = $snapshot->text();

            if ($snapshot->isError || ! str_contains($snapshotText, 'Example Domain')) {
                $this->error('browser_snapshot did not contain Example Domain.');

                return self::FAILURE;
            }

            $link = str_contains($snapshotText, 'More information')
                ? 'More information'
                : (str_contains($snapshotText, 'Learn more') ? 'Learn more' : null);

            if ($link === null) {
                $this->error('browser_snapshot did not contain the expected Example Domain link.');

                return self::FAILURE;
            }

            $this->info("Snapshot verified: Example Domain / {$link}");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            report($throwable);
            $this->error($throwable->getMessage());

            return self::FAILURE;
        } finally {
            $client->disconnect();
        }
    }
}
