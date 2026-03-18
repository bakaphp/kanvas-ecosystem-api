<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for order-provider relationship.
 * Explicitly sets connection to 'commerce' for cross-database pivot operations.
 */
class OrderProvider extends Pivot
{
    protected $connection = 'commerce';
    protected $table = 'order_providers';
    public $incrementing = true;

    /**
     * Get the database-qualified table name for cross-database relationships.
     */
    public static function getQualifiedTableName(): string
    {
        return config('database.connections.commerce.database', 'commerce') . '.order_providers';
    }
}
