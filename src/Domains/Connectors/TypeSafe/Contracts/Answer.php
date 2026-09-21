<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Contracts;

use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;

interface Answer
{
    public function type(): QuestionTypeEnum;
}
