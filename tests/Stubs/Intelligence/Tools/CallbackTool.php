<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence\Tools;

use Closure;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * A tool built from a closure. The declared properties are bound by name onto the closure, the way
 * Neuron binds them onto __invoke(), so the closure must name every declared property it wants; an
 * omitted optional input arrives as null rather than the closure's own default.
 */
final class CallbackTool extends Tool
{
    /**
     * @param list<ToolPropertyInterface> $properties
     */
    public function __construct(
        string $name,
        string $description,
        private readonly Closure $callback,
        array $properties = [],
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->properties = $properties;
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return ($this->callback)(...$arguments);
    }
}
