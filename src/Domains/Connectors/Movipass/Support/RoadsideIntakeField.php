<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Enums\RoadsideIntakeFieldTypeEnum;

final class RoadsideIntakeField
{
    /**
     * @param array<int, string> $options  allowed values, CHOICE only
     * @param string|null $requiredWhen    key of a BOOLEAN field in the same questionnaire; this
     *                                     field is only demanded once that one answers true
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly RoadsideIntakeFieldTypeEnum $type = RoadsideIntakeFieldTypeEnum::TEXT,
        public readonly bool $required = true,
        public readonly array $options = [],
        public readonly ?string $requiredWhen = null,
        public readonly ?string $hint = null,
    ) {
    }

    public static function text(
        string $key,
        string $label,
        bool $required = true,
        ?string $hint = null,
    ): self {
        return new self(key: $key, label: $label, required: $required, hint: $hint);
    }

    public static function boolean(string $key, string $label, bool $required = true): self
    {
        return new self(
            key: $key,
            label: $label,
            type: RoadsideIntakeFieldTypeEnum::BOOLEAN,
            required: $required,
        );
    }

    public static function number(
        string $key,
        string $label,
        bool $required = true,
        ?string $hint = null,
    ): self {
        return new self(
            key: $key,
            label: $label,
            type: RoadsideIntakeFieldTypeEnum::NUMBER,
            required: $required,
            hint: $hint,
        );
    }

    public static function choice(
        string $key,
        string $label,
        array $options,
        bool $required = true,
    ): self {
        return new self(
            key: $key,
            label: $label,
            type: RoadsideIntakeFieldTypeEnum::CHOICE,
            required: $required,
            options: $options,
        );
    }

    public static function dateTime(string $key, string $label, bool $required = true): self
    {
        return new self(
            key: $key,
            label: $label,
            type: RoadsideIntakeFieldTypeEnum::DATETIME,
            required: $required,
        );
    }

    public function dependentOn(string $booleanFieldKey): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            type: $this->type,
            required: $this->required,
            options: $this->options,
            requiredWhen: $booleanFieldKey,
            hint: $this->hint,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'options' => $this->options,
            'required_when' => $this->requiredWhen,
            'hint' => $this->hint,
        ];
    }
}
