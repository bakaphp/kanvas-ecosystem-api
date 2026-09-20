<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use Kanvas\Connectors\TypeSafe\Contracts\Question;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Kanvas\Connectors\TypeSafe\Exceptions\TypeSafeException;
use Override;

/**
 * Rate the state against an ordered rubric.
 *
 * A level's number is its position in `$levels`, starting at 0, and the answer is the
 * probability-weighted average across them — so it lands *between* levels (1.43) rather than on one.
 * That is a rubric position, not a quantity: do not sum, average or threshold it as if it were.
 */
final class Score implements Question
{
    public const int MIN_LEVELS = 2;
    public const int MAX_LEVELS = 10;

    /**
     * @param list<string|array<string, mixed>> $levels Ordered lowest → highest. An entry is a
     *                                                  description, or a structured `{what, examples}`.
     */
    public function __construct(
        public readonly string $instructions,
        public readonly array $levels,
    ) {
        if (trim($instructions) === '') {
            throw new TypeSafeException('A score question needs instructions.');
        }

        // A gap or a string key silently renumbers every level, and the answer is reported against
        // those numbers — so a rubric that is not a list is not the rubric the caller thinks it sent.
        if (! array_is_list($levels)) {
            throw new TypeSafeException('Score levels must be an ordered list; the level number is the array position.');
        }

        $count = count($levels);

        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw new TypeSafeException(
                'A score question needs between ' . self::MIN_LEVELS . ' and ' . self::MAX_LEVELS . ' levels, got ' . $count . '.',
            );
        }
    }

    #[Override]
    public function type(): QuestionTypeEnum
    {
        return QuestionTypeEnum::SCORE;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'type' => $this->type()->value,
            'instructions' => $this->instructions,
            'criteria' => $this->levels,
        ];
    }
}
