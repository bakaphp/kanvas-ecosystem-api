# Neuron Browser Toolkit

Generic browser control for Neuron AI through the remote Playwright Browser Server.

## Configuration

```env
BROWSER_WS_ENDPOINT=ws://browser:3000/playwright
BROWSER_NAVIGATION_TIMEOUT=30000
BROWSER_ACTION_TIMEOUT=15000
BROWSER_SNAPSHOT_MAX_TEXT=20000
BROWSER_SNAPSHOT_MAX_ELEMENTS=150
BROWSER_ALLOWED_HOSTS=
```

`BROWSER_ALLOWED_HOSTS` is an explicit comma-separated exception intended only for controlled test hosts. Normal navigation rejects loopback, private, link-local and reserved addresses, including hostnames that resolve to those ranges.

## Lifecycle

Each `BaseKanvasAgent` instance lazily owns one `BrowserSession`. Registry-resolved browser tools receive that same instance, while another agent run receives a different session and therefore a different Playwright `BrowserContext`. `RunNeuronChatAction` closes tool resources in `finally`, including failed provider/tool runs.

For an agent assembled directly, share one session through the toolkit and close it in `finally`:

```php
$session = app(BrowserSessionFactory::class)->create();
$toolkit = new BrowserToolkit($session);

try {
    $agent->addTool($toolkit);
    $response = $agent->chat($message)->run();
} finally {
    $toolkit->close();
}
```

The toolkit exposes `browser_navigate`, `browser_snapshot`, `browser_click`, `browser_type`, and `browser_press`. Element IDs belong only to the latest snapshot.

## Verification

Direct BrowserSession test:

```bash
php artisan kanvas:browser-test
```

Automated tests, including Neuron's tool loop and concurrent context isolation:

```bash
php vendor/bin/phpunit tests/Intelligence/Agents/Browser
```
