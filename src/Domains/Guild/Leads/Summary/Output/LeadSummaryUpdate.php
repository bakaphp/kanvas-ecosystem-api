<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

final class LeadSummaryUpdate
{
    #[SchemaProperty(description: 'the summary of the whole conversation so far', required: true)]
    public LeadSummarySections $sections;

    /** @var CommitmentOperation[] */
    #[SchemaProperty(description: 'changes to the open items; leave an item out to keep it as it is', required: true, anyOf: [CommitmentOperation::class])]
    #[ArrayOf(CommitmentOperation::class, allowEmpty: true)]
    public array $operations = [];
}
