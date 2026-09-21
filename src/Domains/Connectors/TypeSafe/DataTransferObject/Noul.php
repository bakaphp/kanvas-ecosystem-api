<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use Kanvas\Connectors\TypeSafe\Contracts\Question;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Kanvas\Connectors\TypeSafe\Exceptions\TypeSafeException;
use Override;

/**
 * A yes/no question. The answer is a probability, not a confidence — see {@see NoulAnswer}.
 *
 * Ask one judgement per Noul and combine them in code. A Noul that packs two conditions into one
 * sentence ("is this urgent AND from a paying customer") is the single most common way to get an
 * answer that looks calibrated and is not.
 */
final class Noul implements Question
{
    public function __construct(
        public readonly string $instructions,
        public readonly ?string $whenTrue = null,
        public readonly ?string $whenFalse = null,
    ) {
        if (trim($instructions) === '') {
            throw new TypeSafeException('A noul question needs instructions.');
        }
    }

    #[Override]
    public function type(): QuestionTypeEnum
    {
        return QuestionTypeEnum::NOUL;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $question = [
            'type' => $this->type()->value,
            'instructions' => $this->instructions,
        ];

        $criteria = array_filter(
            ['true' => $this->whenTrue, 'false' => $this->whenFalse],
            static fn (?string $description): bool => $description !== null,
        );

        if ($criteria !== []) {
            $question['criteria'] = $criteria;
        }

        return $question;
    }
}
