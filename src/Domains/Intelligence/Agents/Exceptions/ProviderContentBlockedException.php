<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use NeuronAI\Exceptions\ProviderException;

/**
 * The provider's safety filter refused the turn. Extends ProviderException so anything already
 * catching Neuron provider failures keeps catching it.
 */
class ProviderContentBlockedException extends ProviderException
{
    public function __construct(
        public readonly string $blockReason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
