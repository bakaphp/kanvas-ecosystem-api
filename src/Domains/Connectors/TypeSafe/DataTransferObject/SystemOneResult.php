<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\DataTransferObject;

use Kanvas\Connectors\TypeSafe\Contracts\Answer;
use Kanvas\Connectors\TypeSafe\Enums\QuestionTypeEnum;
use Kanvas\Connectors\TypeSafe\Exceptions\TypeSafeException;

/**
 * One System One response: the answers keyed by the question ids the caller sent, plus what it cost
 * and which model actually answered.
 *
 * `$model` is the resolved version, not the alias that was asked for — it is the value to record in
 * shadow comparisons, since a threshold tuned against one version does not carry to the next.
 */
final class SystemOneResult
{
    /**
     * @param array<string, Answer> $answers
     */
    public function __construct(
        public readonly string $model,
        public readonly array $answers,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly int $latencyMs,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(array $payload, int $latencyMs): self
    {
        $answers = [];
        /** @var array<array-key, mixed> $raw */
        $raw = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

        foreach ($raw as $id => $answer) {
            $answers[(string) $id] = self::parseAnswer((string) $id, is_array($answer) ? $answer : []);
        }

        /** @var array<array-key, mixed> $usage */
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new self(
            model: is_scalar($payload['model'] ?? null) ? (string) $payload['model'] : '',
            answers: $answers,
            inputTokens: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            outputTokens: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            latencyMs: $latencyMs,
        );
    }

    public function noul(string $id): NoulAnswer
    {
        /** @var NoulAnswer */
        return $this->expect($id, NoulAnswer::class);
    }

    public function choice(string $id): ChoiceAnswer
    {
        /** @var ChoiceAnswer */
        return $this->expect($id, ChoiceAnswer::class);
    }

    public function score(string $id): ScoreAnswer
    {
        /** @var ScoreAnswer */
        return $this->expect($id, ScoreAnswer::class);
    }

    /**
     * @param class-string<Answer> $expected
     */
    private function expect(string $id, string $expected): Answer
    {
        $answer = $this->answers[$id] ?? null;

        if ($answer === null) {
            throw new TypeSafeException('TypeSafe did not answer question "' . $id . '".');
        }

        if (! $answer instanceof $expected) {
            throw new TypeSafeException(
                'TypeSafe answered question "' . $id . '" as a ' . $answer->type()->value . ', which is not what was asked.',
            );
        }

        return $answer;
    }

    /**
     * @param array<array-key, mixed> $answer
     */
    private static function parseAnswer(string $id, array $answer): Answer
    {
        $type = QuestionTypeEnum::tryFrom(is_scalar($answer['type'] ?? null) ? (string) $answer['type'] : '');

        return match ($type) {
            QuestionTypeEnum::NOUL => new NoulAnswer(self::requireFloat($answer, 'noul', $id)),
            QuestionTypeEnum::CHOICE => new ChoiceAnswer(
                choice: is_scalar($answer['choice'] ?? null) ? (string) $answer['choice'] : throw new TypeSafeException('TypeSafe returned a choice answer with no choice for question "' . $id . '".'),
                confidence: self::requireFloat($answer, 'confidence', $id),
                probabilities: self::floatMap($answer['probabilities'] ?? null),
            ),
            QuestionTypeEnum::SCORE => new ScoreAnswer(
                score: self::requireFloat($answer, 'score', $id),
                confidence: self::requireFloat($answer, 'confidence', $id),
                legend: is_array($answer['legend'] ?? null) ? $answer['legend'] : [],
                probabilities: self::floatMap($answer['probabilities'] ?? null),
            ),
            default => throw new TypeSafeException('TypeSafe returned an answer of unknown type for question "' . $id . '".'),
        };
    }

    /**
     * Absent is not zero. A missing `noul` defaulted to 0.0 reads as a confident "no", and a missing
     * `confidence` defaulted to 0.0 reads as "fall back to the LLM" — one of those is a wrong answer
     * delivered silently, so neither gets a default.
     *
     * @param array<array-key, mixed> $answer
     */
    private static function requireFloat(array $answer, string $field, string $id): float
    {
        $value = $answer[$field] ?? null;

        if (! is_numeric($value)) {
            throw new TypeSafeException('TypeSafe answer for question "' . $id . '" is missing a numeric "' . $field . '".');
        }

        return (float) $value;
    }

    /**
     * @return array<array-key, float>
     */
    private static function floatMap(mixed $probabilities): array
    {
        return is_array($probabilities) ? array_map(static fn (mixed $value): float => (float) $value, $probabilities) : [];
    }
}
