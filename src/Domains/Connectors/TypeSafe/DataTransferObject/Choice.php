<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use BackedEnum;
use Kanvas\Connectors\TypeSafe\Contracts\Question;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Kanvas\Connectors\TypeSafe\Exceptions\TypeSafeException;
use Override;

/**
 * A Choice is relative — "which of these wins" — so it always returns something. When the real question
 * is "does each of these apply", that is one {@see Noul} per option; the two answer different questions
 * and their numbers are not comparable.
 *
 * Include an explicit escape option ("none", "unclear") whenever "none of these" is a real outcome, or
 * the distribution is forced across options that may all be wrong.
 */
final class Choice implements Question
{
    public const int MIN_OPTIONS = 2;
    public const int MAX_OPTIONS = 255;

    /**
     * @param array<string, string|null> $options Option name => description, or null for a name that
     *                                            speaks for itself.
     */
    public function __construct(
        public readonly string $instructions,
        public readonly array $options,
    ) {
        if (trim($instructions) === '') {
            throw new TypeSafeException('A choice question needs instructions.');
        }

        $count = count($options);

        if ($count < self::MIN_OPTIONS) {
            throw new TypeSafeException('A choice question needs at least ' . self::MIN_OPTIONS . ' options, got ' . $count . '.');
        }

        if ($count > self::MAX_OPTIONS) {
            throw new TypeSafeException(
                'A choice question accepts at most ' . self::MAX_OPTIONS . ' options, got ' . $count
                . '. Shortlist the candidates in code first.',
            );
        }
    }

    /**
     * Build the options from a backed enum so the vocabulary lives in one place and the answer maps
     * straight back with `MyEnum::from($answer->choice)`.
     *
     * @param class-string<BackedEnum> $enum
     * @param array<array-key, string> $descriptions Keyed by enum value; a case with no entry is sent
     *                                               with a null description.
     */
    public static function fromEnum(string $enum, string $instructions, array $descriptions = []): self
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $options[(string) $case->value] = $descriptions[$case->value] ?? null;
        }

        return new self($instructions, $options);
    }

    #[Override]
    public function type(): QuestionTypeEnum
    {
        return QuestionTypeEnum::CHOICE;
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
            // Cast so numeric option names — an int-backed enum's cases, `project_id` shortlists keyed
            // by id — still encode as a JSON object. PHP turns "1" into an int key, and json_encode
            // then emits a list, which the API rejects as a malformed criteria map.
            'criteria' => (object) $this->options,
        ];
    }
}
