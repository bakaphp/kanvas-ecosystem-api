<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The `lines` input shared by the tools that write a purchase order's lines.
 */
trait DescribesPurchaseOrderLines
{
    protected function purchaseOrderLinesProperty(string $description, bool $required): ArrayProperty
    {
        return new ArrayProperty(
            name: 'lines',
            description: $description,
            required: $required,
            items: new ObjectProperty(
                name: 'line',
                description: 'A single order line.',
                properties: [
                    new ToolProperty(
                        name: 'description',
                        type: PropertyType::STRING,
                        description: 'What is being ordered.',
                        required: true,
                    ),
                    new ToolProperty(
                        name: 'quantity',
                        type: PropertyType::NUMBER,
                        description: 'How many units. Above zero.',
                        required: true,
                    ),
                    new ToolProperty(
                        name: 'unit_cost',
                        type: PropertyType::NUMBER,
                        description: 'Cost of one unit in the order currency.',
                        required: true,
                    ),
                    new ToolProperty(
                        name: 'sku',
                        type: PropertyType::STRING,
                        description: 'The item code, when the person gave one.',
                        required: false,
                    ),
                ],
            ),
        );
    }
}
