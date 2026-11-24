<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;

final class CommitmentOperation
{
    public const string ADD = 'add';
    public const string RESOLVE = 'resolve';
    public const string REPLACE = 'replace';
    public const string KEEP = 'keep';

    #[SchemaProperty(description: 'add a new item, resolve or replace an open one, or keep an item marked "source changed" that the messages still support', required: true)]
    #[Enum(values: [self::ADD, self::RESOLVE, self::REPLACE, self::KEEP])]
    public string $op;

    #[SchemaProperty(description: 'id of an existing open item; required for resolve, replace and keep')]
    public ?string $item_id = null;

    #[SchemaProperty(description: 'commitment (a promise someone made) or instruction (how staff want this lead handled); add only')]
    #[Enum(values: ['commitment', 'instruction'], nullable: true)]
    public ?string $kind = null;

    #[SchemaProperty(description: 'the item text; required for add and replace')]
    public ?string $text = null;

    /** @var MessageCitation[] */
    #[SchemaProperty(description: 'the messages that support this change', required: true, anyOf: [MessageCitation::class])]
    #[ArrayOf(MessageCitation::class)]
    #[Count(min: 1)]
    public array $citations = [];
}
