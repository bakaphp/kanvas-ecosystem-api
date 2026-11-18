<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum ToolConfirmationResultEnum: string
{
    case CONFIRMED = 'confirmed';
    case NO_PENDING_PREVIEW = 'no_pending_preview';
    case TOKEN_MISMATCH = 'token_mismatch';
    case SAME_TURN = 'same_turn';
}
