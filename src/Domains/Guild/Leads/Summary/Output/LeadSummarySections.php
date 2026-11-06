<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

/**
 * The free-text part of the summary, the same six sections for every business, plus key facts for what
 * only this business tracks. The model may rewrite every section on every update; what must survive a
 * rewrite lives in the items instead.
 */
final class LeadSummarySections
{
    public const string KEY_FACTS = 'key_facts';

    #[SchemaProperty(description: 'the product, service or property they want')]
    public ?string $interest = null;

    #[SchemaProperty(description: 'budget, payment terms, financing, down payment')]
    public ?string $budget_payment = null;

    #[SchemaProperty(description: 'the location, branch or site involved')]
    public ?string $location = null;

    #[SchemaProperty(description: 'appointments set, moved or cancelled, with dates')]
    public ?string $appointments = null;

    #[SchemaProperty(description: 'objections and concerns')]
    public ?string $objections = null;

    #[SchemaProperty(description: 'where the conversation stands now', required: true)]
    public string $current_state;

    /** @var KeyFact[] */
    #[SchemaProperty(description: 'facts this business tracks that fit no section above', anyOf: [KeyFact::class])]
    #[ArrayOf(KeyFact::class, allowEmpty: true)]
    public array $key_facts = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sections = get_object_vars($this);
        $sections[self::KEY_FACTS] = array_map(
            static fn (KeyFact $fact): array => ['key' => $fact->key, 'value' => $fact->value],
            $this->key_facts,
        );

        return $sections;
    }
}
