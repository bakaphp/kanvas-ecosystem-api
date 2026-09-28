<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use Kanvas\Connectors\TypeSafe\Contracts\Answer;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Override;

final class ChoiceAnswer implements Answer
{
    /**
     * @param array<array-key, float> $probabilities Keyed by option name, for every option in the criteria.
     */
    public function __construct(
        public readonly string $choice,
        public readonly float $confidence,
        public readonly array $probabilities,
    ) {
    }

    #[Override]
    public function type(): QuestionTypeEnum
    {
        return QuestionTypeEnum::CHOICE;
    }
}
