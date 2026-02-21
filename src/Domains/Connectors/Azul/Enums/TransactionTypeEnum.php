<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Enums;

enum TransactionTypeEnum: string
{
    case SALE = 'Sale';
    case HOLD = 'Hold';
    case POST = 'Post';     // Capture after a Hold
    case VOID = 'Void';     // Cancel a Hold or authorized transaction
    case REFUND = 'Refund';
    case CREATE = 'CREATE'; // DataVault token creation
    case DELETE = 'DELETE'; // DataVault token deletion
}
