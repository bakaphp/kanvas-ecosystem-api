<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Enums;

enum OperationKindEnum: string
{
    case SEND = 'send';
    case REPLY = 'reply';
}
