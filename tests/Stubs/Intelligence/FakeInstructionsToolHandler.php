<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Tools\Tool;

class FakeInstructionsToolHandler extends Tool
{
    public const HINT = 'fake-tool-instruction-hint';

    protected string $name = 'fake_instructions_tool';

    public function instructions(): string
    {
        return self::HINT;
    }
}
