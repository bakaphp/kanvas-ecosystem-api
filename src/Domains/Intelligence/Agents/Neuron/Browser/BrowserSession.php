<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

use Playwright\Browser\BrowserContextInterface;
use Playwright\Browser\BrowserInterface;
use Playwright\Configuration\PlaywrightConfig;
use Playwright\Exception\TimeoutException;
use Playwright\Locator\LocatorInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;
use Throwable;

class BrowserSession
{
    private ?PlaywrightClient $client = null;
    private ?BrowserInterface $browser = null;
    private ?BrowserContextInterface $context = null;
    private ?PageInterface $page = null;

    /** @var array<int, string> */
    private array $elements = [];

    private bool $snapshotValid = false;
    private ?string $blockedUrl = null;

    public function __construct(
        private readonly string $wsEndpoint,
        private readonly BrowserUrlValidator $urlValidator,
        private readonly int $navigationTimeout = 30000,
        private readonly int $actionTimeout = 15000,
        private readonly int $snapshotMaxText = 20000,
        private readonly int $snapshotMaxElements = 150,
    ) {
    }

    /** @return array{success: true, url: string, title: string} */
    public function navigate(string $url): array
    {
        $url = $this->urlValidator->validate($url);
        $page = $this->page();
        $this->blockedUrl = null;

        try {
            $page->goto($url, [
                'waitUntil' => 'domcontentloaded',
                'timeout' => (float) $this->navigationTimeout,
            ]);
        } catch (TimeoutException $exception) {
            $this->throwLogged(BrowserErrorCode::ACTION_TIMEOUT, 'Navigation timed out.', $exception);
        } catch (Throwable $exception) {
            if ($this->blockedUrl !== null) {
                $this->throwLogged(
                    BrowserErrorCode::INVALID_URL,
                    'Navigation was blocked because it resolved to a private or reserved address.',
                    $exception,
                );
            }

            $this->throwLogged(BrowserErrorCode::NAVIGATION_FAILED, 'The page could not be loaded.', $exception);
        }

        $this->invalidateSnapshot();

        return [
            'success' => true,
            'url' => $page->url(),
            'title' => $page->title(),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $page = $this->page();

        try {
            $snapshot = $page->evaluate(<<<'JS'
                ({ maxText, maxElements }) => {
                    document.querySelectorAll('[data-kanvas-browser-id]').forEach((element) => {
                        element.removeAttribute('data-kanvas-browser-id');
                    });

                    const selector = [
                        'a', 'button', 'input', 'textarea', 'select',
                        '[role="button"]', '[role="link"]', '[role="textbox"]',
                        '[role="checkbox"]', '[role="radio"]', '[role="combobox"]',
                        '[tabindex]:not([tabindex="-1"])'
                    ].join(',');

                    const isVisible = (element) => {
                        const style = window.getComputedStyle(element);
                        const rect = element.getBoundingClientRect();
                        return style.visibility !== 'hidden'
                            && style.display !== 'none'
                            && rect.width > 0
                            && rect.height > 0;
                    };

                    const inferRole = (element) => {
                        const explicit = element.getAttribute('role');
                        if (explicit) return explicit;
                        const tag = element.tagName.toLowerCase();
                        if (tag === 'a') return 'link';
                        if (tag === 'button') return 'button';
                        if (tag === 'textarea') return 'textbox';
                        if (tag === 'select') return 'combobox';
                        if (tag === 'input') {
                            const type = (element.getAttribute('type') || 'text').toLowerCase();
                            if (type === 'checkbox') return 'checkbox';
                            if (type === 'radio') return 'radio';
                            if (['button', 'submit', 'reset'].includes(type)) return 'button';
                            return 'textbox';
                        }
                        return 'interactive';
                    };

                    const accessibleName = (element) => {
                        const labelledBy = element.getAttribute('aria-labelledby');
                        if (labelledBy) {
                            const value = labelledBy.split(/\s+/)
                                .map((id) => document.getElementById(id)?.innerText || '')
                                .join(' ').trim();
                            if (value) return value;
                        }
                        const aria = element.getAttribute('aria-label');
                        if (aria) return aria.trim();
                        if (element.labels?.length) {
                            const value = Array.from(element.labels)
                                .map((label) => label.innerText || '')
                                .join(' ').trim();
                            if (value) return value;
                        }
                        return (
                            element.getAttribute('alt')
                            || element.getAttribute('title')
                            || element.getAttribute('placeholder')
                            || element.innerText
                            || element.getAttribute('value')
                            || ''
                        ).trim();
                    };

                    const candidates = Array.from(document.querySelectorAll(selector)).filter(isVisible);
                    const elements = candidates.slice(0, maxElements).map((element, index) => {
                        const id = index + 1;
                        element.setAttribute('data-kanvas-browser-id', String(id));
                        const type = element.getAttribute('type');
                        const result = {
                            id,
                            role: inferRole(element),
                            name: accessibleName(element).replace(/\s+/g, ' ').slice(0, 500),
                        };
                        const text = (element.innerText || '').trim().replace(/\s+/g, ' ');
                        const placeholder = element.getAttribute('placeholder');
                        const href = element.getAttribute('href');
                        const disabled = element.disabled || element.getAttribute('aria-disabled') === 'true';
                        if (text && text !== result.name) result.text = text.slice(0, 1000);
                        if (placeholder) result.placeholder = placeholder;
                        if (href) result.href = href;
                        if (type) result.type = type;
                        if (disabled) result.disabled = true;
                        if ('value' in element && type !== 'password' && element.value) {
                            result.value = String(element.value).slice(0, 1000);
                        }
                        return result;
                    });

                    const visibleText = (document.body?.innerText || '').trim();
                    return {
                        text: visibleText.slice(0, maxText),
                        elements,
                        truncated: visibleText.length > maxText || candidates.length > maxElements,
                    };
                }
                JS, [
                    'maxText' => $this->snapshotMaxText,
                    'maxElements' => $this->snapshotMaxElements,
                ]);
        } catch (Throwable $exception) {
            $this->throwLogged(BrowserErrorCode::SNAPSHOT_FAILED, 'The page snapshot could not be created.', $exception);
        }

        if (! is_array($snapshot)) {
            throw new BrowserToolException(BrowserErrorCode::SNAPSHOT_FAILED, 'The page snapshot was invalid.');
        }

        $this->elements = [];
        foreach ($snapshot['elements'] ?? [] as $element) {
            if (is_array($element) && isset($element['id']) && is_int($element['id'])) {
                $this->elements[$element['id']] = sprintf('[data-kanvas-browser-id="%d"]', $element['id']);
            }
        }
        $this->snapshotValid = true;

        return [
            'success' => true,
            'url' => $page->url(),
            'title' => $page->title(),
            'text' => (string) ($snapshot['text'] ?? ''),
            'elements' => array_values($snapshot['elements'] ?? []),
            'truncated' => (bool) ($snapshot['truncated'] ?? false),
        ];
    }

    /** @return array{success: true, url: string} */
    public function click(int $elementId): array
    {
        $page = $this->page();
        $locator = $this->resolveElement($elementId);

        try {
            $locator->click(['timeout' => (float) $this->actionTimeout]);
            $this->settle($page);
        } catch (Throwable $exception) {
            if (! $this->isRecoverableClickInterception($exception)) {
                $code = $exception instanceof TimeoutException
                    ? BrowserErrorCode::ACTION_TIMEOUT
                    : BrowserErrorCode::STALE_ELEMENT;
                $message = $exception instanceof TimeoutException
                    ? "Clicking element {$elementId} timed out."
                    : "Element {$elementId} is no longer valid. Call browser_snapshot again.";

                $this->throwLogged($code, $message, $exception);
            }

            // Autocomplete and sticky-header overlays can cover an otherwise valid target.
            // Re-resolve it after the normal click timeout so a detached/stale snapshot is
            // still rejected, then invoke the element's native click without hit-testing.
            $locator = $this->resolveElement($elementId);

            try {
                $locator->evaluate('(element) => element.click()');
                $this->settle($page);
            } catch (TimeoutException $forcedException) {
                $this->throwLogged(
                    BrowserErrorCode::ACTION_TIMEOUT,
                    "Clicking element {$elementId} timed out, including the DOM fallback.",
                    $forcedException,
                );
            } catch (Throwable $forcedException) {
                $this->throwLogged(
                    BrowserErrorCode::STALE_ELEMENT,
                    "Element {$elementId} could not be clicked after revalidation. Call browser_snapshot again.",
                    $forcedException,
                );
            }
        }

        $this->invalidateSnapshot();

        return ['success' => true, 'url' => $page->url()];
    }

    /** @return array{success: true} */
    public function type(int $elementId, string $text): array
    {
        $locator = $this->resolveElement($elementId);

        try {
            $locator->fill($text, ['timeout' => (float) $this->actionTimeout]);
        } catch (TimeoutException $exception) {
            $this->throwLogged(BrowserErrorCode::ACTION_TIMEOUT, "Typing into element {$elementId} timed out.", $exception);
        } catch (Throwable $exception) {
            $this->throwLogged(BrowserErrorCode::STALE_ELEMENT, "Element {$elementId} is no longer valid. Call browser_snapshot again.", $exception);
        }

        return ['success' => true];
    }

    /** @return array{success: true, url: string} */
    public function press(int $elementId, string $key): array
    {
        $allowedKeys = ['Enter', 'Escape', 'Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
        if (! in_array($key, $allowedKeys, true)) {
            throw new BrowserToolException(
                BrowserErrorCode::INVALID_KEY,
                'Unsupported key. Use Enter, Escape, Tab, or an Arrow key.',
            );
        }

        $page = $this->page();
        $locator = $this->resolveElement($elementId);

        try {
            $locator->press($key, ['timeout' => (float) $this->actionTimeout]);
            $this->settle($page);
        } catch (TimeoutException $exception) {
            $this->throwLogged(BrowserErrorCode::ACTION_TIMEOUT, "Pressing {$key} on element {$elementId} timed out.", $exception);
        } catch (Throwable $exception) {
            $this->throwLogged(BrowserErrorCode::STALE_ELEMENT, "Element {$elementId} is no longer valid. Call browser_snapshot again.", $exception);
        }

        $this->invalidateSnapshot();

        return ['success' => true, 'url' => $page->url()];
    }

    public function close(): void
    {
        try {
            $this->context?->close();
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $this->browser?->close();
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->client?->close();
        $this->page = null;
        $this->context = null;
        $this->browser = null;
        $this->client = null;
        $this->invalidateSnapshot();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function page(): PageInterface
    {
        if ($this->page !== null && ! $this->page->isClosed()) {
            return $this->page;
        }

        if ($this->wsEndpoint === '') {
            throw new BrowserToolException(
                BrowserErrorCode::BROWSER_NOT_CONNECTED,
                'The browser WebSocket endpoint is not configured.',
            );
        }

        try {
            $this->client = PlaywrightFactory::create(new PlaywrightConfig(timeoutMs: $this->actionTimeout));
            $this->browser = $this->client->chromium()->connect($this->wsEndpoint, [
                'timeout' => (float) $this->actionTimeout,
            ]);
            // playwright-php creates a fresh context for every remote connect().
            $this->context = $this->browser->context();
            $this->context->setDefaultTimeout($this->actionTimeout);
            $this->context->setDefaultNavigationTimeout($this->navigationTimeout);
            $this->context->route('**/*', function ($route): void {
                try {
                    $this->urlValidator->validate($route->request()->url());
                    $route->continue();
                } catch (BrowserToolException) {
                    $this->blockedUrl = $route->request()->url();
                    $route->abort('blockedbyclient');
                }
            });
            $this->page = $this->context->newPage();
        } catch (BrowserToolException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->close();
            $this->throwLogged(
                BrowserErrorCode::BROWSER_NOT_CONNECTED,
                'Could not connect to the browser service.',
                $exception,
            );
        }

        return $this->page;
    }

    private function resolveElement(int $elementId): LocatorInterface
    {
        if (! $this->snapshotValid) {
            throw new BrowserToolException(
                BrowserErrorCode::STALE_ELEMENT,
                "Element {$elementId} is no longer valid. Call browser_snapshot again.",
            );
        }

        $selector = $this->elements[$elementId] ?? null;
        if ($selector === null) {
            throw new BrowserToolException(
                BrowserErrorCode::ELEMENT_NOT_FOUND,
                "Element {$elementId} was not present in the latest browser snapshot.",
            );
        }

        $locator = $this->page()->locator($selector);
        if ($locator->count() !== 1 || ! $locator->isAttached()) {
            throw new BrowserToolException(
                BrowserErrorCode::STALE_ELEMENT,
                "Element {$elementId} is no longer valid. Call browser_snapshot again.",
            );
        }

        return $locator;
    }

    private function settle(PageInterface $page): void
    {
        try {
            $page->waitForLoadState('domcontentloaded', ['timeout' => 1000.0]);
        } catch (Throwable) {
            // Actions on SPAs often do not trigger a document navigation.
        }
    }

    private function isRecoverableClickInterception(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'intercepts pointer events')
            || str_contains($message, 'element not actionable');
    }

    private function invalidateSnapshot(): void
    {
        $this->elements = [];
        $this->snapshotValid = false;
    }

    private function throwLogged(BrowserErrorCode $code, string $message, Throwable $exception): never
    {
        report($exception);

        throw new BrowserToolException($code, $message, $exception);
    }
}
