<?php

declare(strict_types=1);

namespace Tests\Traits;

use NeuronAI\Chat\Messages\Message;

trait ReadsMessageContents
{
    /**
     * @param list<Message> $messages
     * @return list<string>
     */
    protected function contents(array $messages): array
    {
        return array_map(static fn (Message $message): string => (string) $message->getContent(), $messages);
    }
}
