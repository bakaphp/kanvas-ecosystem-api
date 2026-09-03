<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\DataTransferObject;

use Kanvas\NervousSystem\Plan\Enums\WorkClassEnum;

/**
 * What a request has to say before anyone acts on it.
 *
 * The gate exists because the executors cannot ask. `DispatchLongTaskTool` states it outright in its
 * own parameter description — "the hosted agent CANNOT ask follow-up questions… ambiguity produces
 * the wrong result" — so the only place a missing detail can be caught is before dispatch. This
 * mirrors `StartHostedTaskSessionAction::assertDispatchable()`, which already refuses an unresolvable
 * repo slug, one level up: refuse an unresolvable *request*.
 */
final readonly class Brief
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public WorkClassEnum $workClass,
        public array $fields = [],
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function of(WorkClassEnum $workClass, array $fields = []): self
    {
        return new self($workClass, $fields);
    }

    /**
     * Fields the class requires that this brief has not answered. Empty means dispatchable.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach ($this->workClass->requiredFields() as $field) {
            $value = $this->fields[$field] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function isDispatchable(): bool
    {
        return $this->missingFields() === [];
    }

    /**
     * The questions still to ask, in the order the fields were declared — so an interview walks the
     * checklist rather than jumping around.
     *
     * @return list<string>
     */
    public function outstandingQuestions(): array
    {
        return array_map(
            fn (string $field): string => $this->workClass->questionFor($field),
            $this->missingFields(),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function withFields(array $fields): self
    {
        return new self($this->workClass, [...$this->fields, ...$fields]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'work_class' => $this->workClass->value,
            'fields' => $this->fields,
            'missing_fields' => $this->missingFields(),
            'dispatchable' => $this->isDispatchable(),
        ];
    }
}
