<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Contracts;

use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;

interface Question
{
    public function type(): QuestionTypeEnum;

    /**
     * @return array<string, mixed> One entry of the request's `questions` map.
     */
    public function toArray(): array;
}
