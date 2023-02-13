<?php

declare(strict_types=1);

namespace Kanvas\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait HasSchemaAccessors
{
    public static Model $schemaInstance;
    public static array $schemaColumnNames;
    public static string $schemaTableName;

    /**
     * Returns singleton of model.
     *
     * @return Model
     */
    protected static function schemaInstance(): Model
    {
        if (empty(static::$schemaInstance)) {
            static::$schemaInstance = new static();
        }
        return static::$schemaInstance;
    }

    /**
     * Returns the table name for a given model.
     *
     * @return string
     */
    public static function getSchemaTableName(): string
    {
        if (empty(static::$schemaTableName)) {
            static::$schemaTableName = static::schemaInstance()->getTable();
        }
        return static::$schemaTableName;
    }

    /**
     * Fetches column names from the database schema.
     *
     * @return array
     */
    public static function getSchemaColumnNames(): array
    {
        if (empty(static::$schemaColumnNames)) {
            static::$schemaColumnNames = Schema::getColumnListing(static::getSchemaTableName());
        }
        return static::$schemaColumnNames;
    }

    /**
     * does it exist in the schema?
     *
     * @param string $name
     *
     * @return bool
     */
    public static function schemaHasColumn(string $name): bool
    {
        return in_array($name, static::getSchemaColumnNames());
    }
}
