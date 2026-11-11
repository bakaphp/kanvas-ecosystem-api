<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Exceptions;

use InvalidArgumentException;

/**
 * Only arguments the model sent raise this, so `invalid_input` never blames the model for an
 * InvalidArgumentException thrown further down (HTTP client, PhpSpreadsheet...).
 */
class InvalidToolInputException extends InvalidArgumentException
{
}
