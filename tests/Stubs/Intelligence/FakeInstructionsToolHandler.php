<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

class FakeInstructionsToolHandler
{
    public const HINT = 'fake-tool-instruction-hint';

    public function instructions(): string
    {
        return self::HINT;
    }
}
