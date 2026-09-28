<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Throwable;

/** Minimal chat() handler double that throws instead of talking to a real provider — for RunNeuronChatAction error-path tests. */
class ThrowingNeuronHandlerStub
{
    public function __construct(private readonly Throwable $exception)
    {
    }

    public function chat(mixed $messages = []): never
    {
        throw $this->exception;
    }
}
