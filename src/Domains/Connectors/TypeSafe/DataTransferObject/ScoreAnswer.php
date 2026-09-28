<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use Kanvas\Connectors\TypeSafe\Contracts\Answer;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Override;

final class ScoreAnswer implements Answer
{
    /**
     * @param array<array-key, mixed> $legend        The rubric echoed back, keyed by level number.
     * @param array<array-key, float> $probabilities Keyed by level number.
     */
    public function __construct(
        public readonly float $score,
        public readonly float $confidence,
        public readonly array $legend,
        public readonly array $probabilities,
    ) {
    }

    #[Override]
    public function type(): QuestionTypeEnum
    {
        return QuestionTypeEnum::SCORE;
    }
}
