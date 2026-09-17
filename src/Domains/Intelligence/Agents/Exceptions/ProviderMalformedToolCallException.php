<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use NeuronAI\Exceptions\ProviderException;

/**
 * The model started a tool call and emitted arguments the provider could not parse. Extends
 * ProviderException so anything already catching Neuron provider failures keeps catching it.
 */
class ProviderMalformedToolCallException extends ProviderException
{
}
