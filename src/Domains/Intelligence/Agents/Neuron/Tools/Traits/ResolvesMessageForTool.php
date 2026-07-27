<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Social\Messages\Models\Message;

/**
 * Look up a Social Message by id, scoped to the tool's app + company (from HasKanvasContext), returning
 * either the Message OR a structured error array the LLM can act on — so a hallucinated message_id never
 * crashes the chat with an unhandled null-dereference. Mirrors ResolvesPlanForTool / ResolvesTaskForTool.
 *
 * Pattern:
 *
 *   $result = $this->resolveMessageOrError($message_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to the agent
 *   }
 *   $message = $result;     // typed Message from here on
 */
trait ResolvesMessageForTool
{
    /**
     * @return Message|array{error: string}
     */
    protected function resolveMessageOrError(int $id, ?string $notFoundMessage = null): Message|array
    {
        $message = Message::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->first();

        if ($message instanceof Message) {
            return $message;
        }

        return ['error' => $notFoundMessage ?? "Message {$id} was not found."];
    }
}
