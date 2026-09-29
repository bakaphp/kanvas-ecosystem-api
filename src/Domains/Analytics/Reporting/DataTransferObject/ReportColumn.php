<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\DataTransferObject;

/**
 * One column of a flat report table.
 *
 * `label` is not decoration: `describe_report_model` hands it to the agent, and the Gestor renders
 * it. It is why report columns are named in the tenant's own vocabulary (`nivel`, not `str_7`) —
 * the column name *is* the schema the agent reasons about.
 */
final class ReportColumn
{
    /**
     * @param string $type a MySQL column type, written as it appears in DDL
     * @param bool $multiValued a JSON array queried with MEMBER OF, backed by a multi-valued
     *                          index. Use for a one-to-many child that only ever has ONE filter
     *                          condition applied to it; anything correlated needs its own grain.
     */
    /**
     * @param string|null $indexCast element type a multi-valued index casts to, e.g. `CHAR(64)`.
     *                               Only meaningful when $multiValued is true.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $label = null,
        public readonly bool $indexed = false,
        public readonly bool $multiValued = false,
        public readonly bool $nullable = true,
        public readonly ?string $indexCast = null,
    ) {
    }

    public static function string(string $name, int $length = 255, ?string $label = null, bool $indexed = false): self
    {
        return new self($name, "varchar({$length})", $label, $indexed);
    }

    public static function text(string $name, ?string $label = null): self
    {
        return new self($name, 'text', $label);
    }

    public static function integer(string $name, ?string $label = null, bool $indexed = false): self
    {
        return new self($name, 'int', $label, $indexed);
    }

    public static function decimal(string $name, int $precision = 12, int $scale = 2, ?string $label = null): self
    {
        return new self($name, "decimal({$precision},{$scale})", $label);
    }

    public static function boolean(string $name, ?string $label = null, bool $indexed = false): self
    {
        return new self($name, 'tinyint(1)', $label, $indexed);
    }

    public static function date(string $name, ?string $label = null, bool $indexed = false): self
    {
        return new self($name, 'date', $label, $indexed);
    }

    public static function datetime(string $name, ?string $label = null): self
    {
        return new self($name, 'datetime', $label);
    }

    /**
     * A JSON array with a multi-valued index — `'X' MEMBER OF (col)` uses the index.
     *
     * @param string $castAs the element type the index casts to, e.g. `CHAR(64)` or `DATE`
     */
    public static function jsonArray(string $name, string $castAs = 'CHAR(64)', ?string $label = null): self
    {
        return new self(
            name: $name,
            type: 'json',
            label: $label,
            indexed: true,
            multiValued: true,
            indexCast: $castAs,
        );
    }

    public function definition(): string
    {
        return sprintf('`%s` %s %s', $this->name, $this->type, $this->nullable ? 'NULL' : 'NOT NULL');
    }
}
