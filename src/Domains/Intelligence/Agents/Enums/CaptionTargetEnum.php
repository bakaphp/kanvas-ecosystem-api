<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * Which history store CaptionMessageImagesJob writes the backfilled captions back to.
 * The two Neuron histories live in different tables, so the job needs to know which one.
 */
enum CaptionTargetEnum: string
{
    // Kanvas\Social\Messages\Models\Message — SalesAssistKanvasMessageHistory reads message.image_descriptions
    case SOCIAL_MESSAGE = 'social_message';

    // intelligence.agent_conversation_messages — KanvasMessageHistory reads the attachments column
    case CONVERSATION_MESSAGE = 'conversation_message';
}
