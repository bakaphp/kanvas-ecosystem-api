<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Browser;

use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserSession;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserUrlValidator;
use Tests\TestCase;

class BrowserSessionIntegrationTest extends TestCase
{
    public function testItNavigatesSnapshotsTypesPressesAndCleansUp(): void
    {
        $endpoint = (string) getenv('BROWSER_WS_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('BROWSER_WS_ENDPOINT is not configured.');
        }

        $browser = new BrowserSession($endpoint, new BrowserUrlValidator(['browser-fixture']));

        try {
            $navigation = $browser->navigate('http://browser-fixture/');
            $this->assertSame('Browser Tool Test', $navigation['title']);

            $snapshot = $browser->snapshot();
            $input = collect($snapshot['elements'])->firstWhere('role', 'textbox');
            $this->assertIsArray($input);

            $browser->type((int) $input['id'], 'hello world');
            $browser->press((int) $input['id'], 'Enter');

            $result = $browser->snapshot();
            $this->assertStringContainsString('Search result: hello world', $result['text']);
        } finally {
            $browser->close();
        }
    }

    public function testConcurrentRunsHaveIndependentBrowserContexts(): void
    {
        $endpoint = (string) getenv('BROWSER_WS_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('BROWSER_WS_ENDPOINT is not configured.');
        }

        $first = new BrowserSession($endpoint, new BrowserUrlValidator(['browser-fixture']));
        $second = new BrowserSession($endpoint, new BrowserUrlValidator(['browser-fixture']));

        try {
            $first->navigate('http://browser-fixture/');
            $second->navigate('http://browser-fixture/');

            $firstSnapshot = $first->snapshot();
            $secondSnapshot = $second->snapshot();
            $firstInput = collect($firstSnapshot['elements'])->firstWhere('role', 'textbox');
            $secondInput = collect($secondSnapshot['elements'])->firstWhere('role', 'textbox');

            $first->type((int) $firstInput['id'], 'run A');
            $second->type((int) $secondInput['id'], 'run B');

            $firstValue = collect($first->snapshot()['elements'])->firstWhere('role', 'textbox')['value'] ?? null;
            $secondValue = collect($second->snapshot()['elements'])->firstWhere('role', 'textbox')['value'] ?? null;

            $this->assertSame('run A', $firstValue);
            $this->assertSame('run B', $secondValue);
        } finally {
            $first->close();
            $second->close();
        }
    }

    public function testItFallsBackToADomClickWhenAnOverlayInterceptsPointerEvents(): void
    {
        $endpoint = (string) getenv('BROWSER_WS_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('BROWSER_WS_ENDPOINT is not configured.');
        }

        $browser = new BrowserSession(
            $endpoint,
            new BrowserUrlValidator(['browser-fixture']),
            actionTimeout: 500,
        );

        try {
            $browser->navigate('http://browser-fixture/');
            $snapshot = $browser->snapshot();
            $coveredButton = collect($snapshot['elements'])->firstWhere('name', 'Covered action');
            $this->assertIsArray($coveredButton);

            $browser->click((int) $coveredButton['id']);

            $result = $browser->snapshot();
            $this->assertStringContainsString('Covered action clicked.', $result['text']);
        } finally {
            $browser->close();
        }
    }
}
