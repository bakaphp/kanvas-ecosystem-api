<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * A cited message id. An object and not a bare int because SchemaProperty `anyOf` only takes classes,
 * and without `anyOf` Neuron describes every array property as a list of strings.
 */
final class MessageCitation
{
    #[SchemaProperty(description: 'id of a message in this update\'s input', required: true)]
    public int $message_id;
}
