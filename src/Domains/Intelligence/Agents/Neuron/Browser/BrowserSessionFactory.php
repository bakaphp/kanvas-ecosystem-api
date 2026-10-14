<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

class BrowserSessionFactory
{
    public function create(): BrowserSession
    {
        return new BrowserSession(
            wsEndpoint: (string) config('browser.ws_endpoint', ''),
            urlValidator: new BrowserUrlValidator(config('browser.allowed_hosts', [])),
            navigationTimeout: (int) config('browser.navigation_timeout', 30000),
            actionTimeout: (int) config('browser.action_timeout', 15000),
            snapshotMaxText: (int) config('browser.snapshot_max_text', 20000),
            snapshotMaxElements: (int) config('browser.snapshot_max_elements', 150),
        );
    }
}
