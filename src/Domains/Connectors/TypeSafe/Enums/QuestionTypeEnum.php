<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Enums;

enum QuestionTypeEnum: string
{
    case NOUL = 'noul';
    case CHOICE = 'choice';
    case SCORE = 'score';
}
