<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use ReflectionMethod;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

/**
 * Stripe rejects an empty `default_value` on a text/numeric custom field with
 * `parameter_invalid_empty` — it reads "" as an attempt to unset the param —
 * and rejects one longer than 50 characters even when `maximum_length` is
 * higher. normalizeCustomFields must strip the empties and truncate the rest so
 * neither case fails the whole payment-link request.
 */
final class StripePaymentLinkCustomFieldsTest extends TestCase
{
    public function testStripsEmptyTextDefaultValue(): void
    {
        $normalized = $this->normalizeCustomFields([
            [
                'key' => 'customer_name',
                'type' => 'text',
                'optional' => false,
                'text' => ['default_value' => 'Jane Doe', 'maximum_length' => 200],
            ],
            [
                'key' => 'stock_no',
                'type' => 'text',
                'optional' => true,
                'text' => ['default_value' => '', 'maximum_length' => 50],
            ],
            [
                'key' => 'sales_person',
                'type' => 'text',
                'optional' => true,
                'text' => ['default_value' => '   ', 'maximum_length' => 50],
            ],
        ]);

        $this->assertSame('Jane Doe', $normalized[0]['text']['default_value']);
        $this->assertArrayNotHasKey('default_value', $normalized[1]['text']);
        $this->assertArrayNotHasKey('default_value', $normalized[2]['text']);
        $this->assertSame(50, $normalized[1]['text']['maximum_length']);
    }

    public function testStripsEmptyNumericDefaultValueAndKeepsNonEmpty(): void
    {
        $normalized = $this->normalizeCustomFields([
            [
                'key' => 'quantity',
                'type' => 'numeric',
                'numeric' => ['default_value' => '', 'maximum_length' => 5],
            ],
            [
                'key' => 'units',
                'type' => 'numeric',
                'numeric' => ['default_value' => '3'],
            ],
        ]);

        $this->assertArrayNotHasKey('default_value', $normalized[0]['numeric']);
        $this->assertSame('3', $normalized[1]['numeric']['default_value']);
    }

    public function testTruncatesDefaultValueToStripeFiftyCharacterCap(): void
    {
        $longName = str_repeat('a', 80);

        $normalized = $this->normalizeCustomFields([
            [
                'key' => 'customer_name',
                'type' => 'text',
                'optional' => false,
                'text' => ['default_value' => $longName, 'maximum_length' => 200],
            ],
        ]);

        $this->assertSame(str_repeat('a', 50), $normalized[0]['text']['default_value']);
        $this->assertSame(200, $normalized[0]['text']['maximum_length']);
    }

    public function testTruncatesDefaultValueToFieldMaximumLengthWhenLower(): void
    {
        $normalized = $this->normalizeCustomFields([
            [
                'key' => 'stock_no',
                'type' => 'text',
                'optional' => true,
                'text' => ['default_value' => str_repeat('b', 40), 'maximum_length' => 10],
            ],
        ]);

        $this->assertSame(str_repeat('b', 10), $normalized[0]['text']['default_value']);
    }

    public function testTruncationIsMultibyteSafe(): void
    {
        $normalized = $this->normalizeCustomFields([
            [
                'key' => 'customer_name',
                'type' => 'text',
                'text' => ['default_value' => str_repeat('ñ', 60), 'maximum_length' => 200],
            ],
        ]);

        $this->assertSame(str_repeat('ñ', 50), $normalized[0]['text']['default_value']);
    }

    /**
     * The guarantee is "never emit an empty default_value". A field carrying maximum_length 0 — or a
     * non-numeric one, which casts to 0 — would otherwise truncate to '' and hand Stripe back the
     * parameter_invalid_empty this method exists to prevent.
     */
    public function testDoesNotTruncateToEmptyWhenMaximumLengthIsUnusable(): void
    {
        $normalized = $this->normalizeCustomFields([
            ['key' => 'a', 'type' => 'text', 'text' => ['default_value' => 'Jane', 'maximum_length' => 0]],
            ['key' => 'b', 'type' => 'text', 'text' => ['default_value' => 'Jane', 'maximum_length' => 'oops']],
        ]);

        $this->assertNotSame('', $normalized[0]['text']['default_value']);
        $this->assertNotSame('', $normalized[1]['text']['default_value']);
    }

    private function normalizeCustomFields(array $customFields): array
    {
        $app = $this->createMock(AppInterface::class);
        $service = new StripePaymentLinkService($app, null, new FakeStripeClient());

        $method = new ReflectionMethod($service, 'normalizeCustomFields');

        return $method->invoke($service, $customFields);
    }
}
