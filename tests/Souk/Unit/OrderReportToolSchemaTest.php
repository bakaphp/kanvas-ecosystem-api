<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderBreakdownTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderCommissionStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderFulfillmentStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderPaymentStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderProviderStatsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\OrderTrendTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsOrderReports;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

final class OrderReportToolSchemaTest extends TestCaseUnit
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function toolProvider(): array
    {
        return [
            'order_breakdown' => [OrderBreakdownTool::class],
            'order_payment_stats' => [OrderPaymentStatsTool::class],
            'order_commission_stats' => [OrderCommissionStatsTool::class],
            'order_trend' => [OrderTrendTool::class],
            'order_fulfillment_stats' => [OrderFulfillmentStatsTool::class],
            'order_provider_stats' => [OrderProviderStatsTool::class],
        ];
    }

    /**
     * Neuron's ToolProperty can't emit a JSON-schema `items` for an ARRAY, so a strict provider
     * (Gemini) rejects the whole request with HTTP 400. order_types must stay a scalar STRING.
     *
     * @param class-string $toolClass
     */
    #[DataProvider('toolProvider')]
    public function testOrderTypesAndTagsAreScalarStringsNotArrays(string $toolClass): void
    {
        $tool = new $toolClass();

        $byName = [];
        foreach ($tool->getProperties() as $property) {
            $byName[$property->getName()] = $property;
        }

        foreach (['order_types', 'tags'] as $name) {
            $this->assertArrayHasKey($name, $byName, $name . ' property is missing');
            $this->assertSame(PropertyType::STRING, $byName[$name]->getType());
        }
    }

    public function testParseNamesSplitsTrimsAndNormalizesEmpty(): void
    {
        $parser = new class () {
            use ReportsToolOutcome;
            use RunsOrderReports;

            /**
             * @return list<string>|null
             */
            public function parse(?string $value): ?array
            {
                return $this->parseNames($value);
            }
        };

        $this->assertNull($parser->parse(null));
        $this->assertNull($parser->parse('   '));
        $this->assertSame(['movipass'], $parser->parse('movipass'));
        $this->assertSame(['movipass', 'paso_rapido'], $parser->parse(' movipass , paso_rapido '));
        $this->assertSame(['a', 'b'], $parser->parse('a,,b,'));
    }
}
