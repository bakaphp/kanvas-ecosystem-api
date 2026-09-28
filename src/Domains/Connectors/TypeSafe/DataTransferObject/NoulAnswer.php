<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use Kanvas\Connectors\TypeSafe\Contracts\Answer;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Override;

/**
 * A Noul carries **no confidence** — `$noul` is the probability of yes and it is the only number
 * there is. Gate on it directly, and tune the threshold per decision: a cutoff that worked for a
 * Choice's `confidence` means nothing here, and P(x) + P(not x) does not add up to 1 across two Nouls.
 */
final class NoulAnswer implements Answer
{
    public function __construct(
        public readonly float $noul,
    ) {
    }

    #[Override]
    public function type(): QuestionTypeEnum
    {
        return QuestionTypeEnum::NOUL;
    }
}
