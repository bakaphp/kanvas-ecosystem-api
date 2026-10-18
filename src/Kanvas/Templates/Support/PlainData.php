<?php

declare(strict_types=1);

namespace Kanvas\Templates\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use JsonSerializable;
use Kanvas\Templates\Contracts\ExposesTemplateData;
use stdClass;
use Stringable;
use UnitEnum;

/**
 * Turns a context of models and objects into plain arrays and scalars before stored text (a
 * template, a rule condition) is evaluated against it. Reading an unknown property on an Eloquent
 * model calls the method of that name, so a live model must never reach stored text.
 */
final class PlainData
{
    private const int MAX_DEPTH = 8;

    public static function from(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        $value = match (true) {
            // Before JsonSerializable: Carbon serializes to UTC and would lose its timezone.
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof ExposesTemplateData => $value->toTemplateData(),
            $value instanceof Model => array_merge($value->attributesToArray(), $value->getRelations()),
            $value instanceof Enumerable => $value->all(),
            $value instanceof stdClass => (array) $value,
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            default => $value,
        };

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::from($item, $depth + 1), $value);
        }

        return is_object($value) ? null : $value;
    }
}
