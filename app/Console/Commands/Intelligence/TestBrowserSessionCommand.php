<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserSessionFactory;
use Throwable;

class TestBrowserSessionCommand extends Command
{
    protected $signature = 'kanvas:browser-test {--url=https://example.com}';
    protected $description = 'Verify BrowserSession against the remote Playwright Browser Server without an LLM';

    public function handle(BrowserSessionFactory $factory): int
    {
        $browser = $factory->create();

        try {
            $navigation = $browser->navigate((string) $this->option('url'));
            $this->line(json_encode($navigation, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            $snapshot = $browser->snapshot();
            $link = collect($snapshot['elements'])->first(
                fn (array $element): bool => str_contains(
                    strtolower((string) ($element['name'] ?? $element['text'] ?? '')),
                    'more information',
                ),
            ) ?? collect($snapshot['elements'])->firstWhere('role', 'link');

            if (! is_array($link) || ! isset($link['id'])) {
                $this->error('No link was found in the snapshot.');

                return self::FAILURE;
            }

            $browser->click((int) $link['id']);
            $afterClick = $browser->snapshot();
            $this->line(json_encode([
                'clicked_element_id' => $link['id'],
                'url' => $afterClick['url'],
                'title' => $afterClick['title'],
                'elements' => count($afterClick['elements']),
                'truncated' => $afterClick['truncated'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $this->info('BrowserSession remote-control test passed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $browser->close();
        }
    }
}
