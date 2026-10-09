<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasSummarization;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

/**
 * What a row in `agent_conversation_messages` is besides a turn. A conversational turn has no kind;
 * the rest are what the model needs and the person never sees: the compaction summary and the two
 * halves of a tool round. Stored in its own indexed column so the chat can leave them out without
 * opening `meta`.
 */
enum ConversationMessageKindEnum: string
{
    case SUMMARY = 'summary';
    case TOOL_CALL = 'tool_call';
    case TOOL_RESULT = 'tool_call_result';

    public static function of(Message $message): ?self
    {
        return match (true) {
            $message->getMetadata(KanvasSummarization::SUMMARY_FLAG) === true => self::SUMMARY,
            $message instanceof ToolResultMessage => self::TOOL_RESULT,
            $message instanceof ToolCallMessage => self::TOOL_CALL,
            default => null,
        };
    }
}
