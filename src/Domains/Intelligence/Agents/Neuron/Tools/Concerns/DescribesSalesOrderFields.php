<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Kanvas\Scribe\SalesOrders\Services\SalesOrderValidityService;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The sales order fields an agent gives: one schema for the tool that registers an order and the one that
 * corrects it, so a field means the same in both.
 */
trait DescribesSalesOrderFields
{
    /**
     * @return list<ToolProperty|ArrayProperty|ObjectProperty>
     */
    protected function salesOrderFieldProperties(string $linesDescription): array
    {
        return [
            new ToolProperty(
                name: 'customer_po_number',
                type: PropertyType::STRING,
                description: 'The number on the customer\'s purchase order.',
                required: false,
            ),
            new ToolProperty(
                name: 'customer_po_date',
                type: PropertyType::STRING,
                description: 'The date on the customer\'s purchase order, as YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Three-letter currency code.',
                required: false,
            ),
            new ArrayProperty(
                name: 'lines',
                description: $linesDescription,
                required: false,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'A single order line.',
                    properties: [
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'The product.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'Quantity ordered. Above zero.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'unit_price',
                            type: PropertyType::NUMBER,
                            description: 'Price of one unit in the order currency.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'sku',
                            type: PropertyType::STRING,
                            description: 'The item code, when there is one.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'incoterm',
                type: PropertyType::STRING,
                description: 'The Incoterm on the customer\'s order, e.g. FOB or CIF.',
                required: false,
            ),
            new ToolProperty(
                name: 'consignee',
                type: PropertyType::STRING,
                description: 'Who the goods are consigned to.',
                required: false,
            ),
            new ToolProperty(
                name: 'payment_terms_days',
                type: PropertyType::INTEGER,
                description: 'Payment term in days on the customer\'s order, e.g. 50.',
                required: false,
            ),
            new ToolProperty(
                name: 'requested_date',
                type: PropertyType::STRING,
                description: 'The date the customer requires the goods, as YYYY-MM-DD.',
                required: false,
            ),
            new ObjectProperty(
                name: 'signature_checklist',
                description: 'Which validity checks on the customer\'s commitment are confirmed. Mark only what was '
                    . 'actually checked on the document.',
                required: false,
                properties: [
                    new ToolProperty(
                        name: SalesOrderValidityService::AUTHORIZED_SIGNER,
                        type: PropertyType::BOOLEAN,
                        description: 'Signed by an authorized representative (legal representative or with power '
                            . 'of attorney).',
                        required: false,
                    ),
                    new ToolProperty(
                        name: SalesOrderValidityService::STAMP_AND_DATE,
                        type: PropertyType::BOOLEAN,
                        description: 'Legible stamp and date.',
                        required: false,
                    ),
                    new ToolProperty(
                        name: SalesOrderValidityService::MATCHING_LEGAL_IDENTITY,
                        type: PropertyType::BOOLEAN,
                        description: 'Legal name and tax id identical to the proforma.',
                        required: false,
                    ),
                    new ToolProperty(
                        name: SalesOrderValidityService::ALL_PAGES_INITIALED,
                        type: PropertyType::BOOLEAN,
                        description: 'Every page initialed, with no crossings-out or handwritten changes to the terms.',
                        required: false,
                    ),
                ],
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Anything the team should know about this order.',
                required: false,
            ),
        ];
    }
}
