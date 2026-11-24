<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * A fact the business tracks that has no fixed section: `trade_in` for a dealer, `policy_term` for a
 * broker. The key is the configured name when one matches (`agent_lead_summary_keys`), so the fact lines
 * up with the lead's custom field of the same name.
 */
final class KeyFact
{
    #[SchemaProperty(description: 'snake_case name of the fact; use the configured key when one matches', required: true)]
    public string $key;

    #[SchemaProperty(description: 'the fact as stated in the conversation', required: true)]
    public string $value;
}
