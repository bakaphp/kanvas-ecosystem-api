<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The free-text part of the summary. The model may rewrite every section on every update; what must
 * survive a rewrite lives in the items instead.
 */
final class LeadSummarySections
{
    #[SchemaProperty(description: 'vehicle, product or service they want')]
    public ?string $vehicle_of_interest = null;

    #[SchemaProperty(description: 'budget, financing and down payment')]
    public ?string $budget_financing = null;

    #[SchemaProperty(description: 'trade-in details')]
    public ?string $trade_in = null;

    #[SchemaProperty(description: 'location or lot they were sent to')]
    public ?string $lot = null;

    #[SchemaProperty(description: 'appointments set, moved or cancelled, with dates')]
    public ?string $appointments = null;

    #[SchemaProperty(description: 'objections and concerns')]
    public ?string $objections = null;

    #[SchemaProperty(description: 'where the conversation stands now', required: true)]
    public string $current_state;

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
