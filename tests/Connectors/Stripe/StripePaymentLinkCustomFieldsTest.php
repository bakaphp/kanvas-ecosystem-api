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
 * `parameter_invalid_empty` — it reads "" as an attempt to unset the param.
 * normalizeCustomFields must strip those so an optional field with no value is
 * sent blank instead of failing the whole payment-link request.
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

    private function normalizeCustomFields(array $customFields): array
    {
        $app = $this->createMock(AppInterface::class);
        $service = new StripePaymentLinkService($app, null, new FakeStripeClient());

        $method = new ReflectionMethod($service, 'normalizeCustomFields');

        return $method->invoke($service, $customFields);
    }
}
