<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use ReflectionMethod;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

/**
 * Stripe rejects an empty `after_completion[redirect][url]`
 * (`parameter_invalid_empty` — it reads "" as an attempt to unset the param,
 * which cannot be unset). When a lead message has no action_link, success_url
 * arrives as "" and the after_completion block must be omitted, not sent empty.
 */
final class StripePaymentLinkAfterCompletionTest extends TestCase
{
    public function testReturnsNullWhenSuccessUrlIsEmptyString(): void
    {
        $this->assertNull($this->buildAfterCompletion(['success_url' => '']));
    }

    public function testReturnsNullWhenSuccessUrlIsMissing(): void
    {
        $this->assertNull($this->buildAfterCompletion([]));
    }

    public function testBuildsRedirectWhenSuccessUrlIsPresent(): void
    {
        $result = $this->buildAfterCompletion(['success_url' => 'https://example.com/thanks']);

        $this->assertSame(
            [
                'type' => 'redirect',
                'redirect' => ['url' => 'https://example.com/thanks'],
            ],
            $result
        );
    }

    private function buildAfterCompletion(array $options): ?array
    {
        $app = $this->createMock(AppInterface::class);
        $service = new StripePaymentLinkService($app, null, new FakeStripeClient());

        $method = new ReflectionMethod($service, 'buildAfterCompletion');

        return $method->invoke($service, $options);
    }
}
