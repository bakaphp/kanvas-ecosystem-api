<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgBuilderDirective;

class WhereJsonNestedKeyDirective extends BaseDirective implements ArgBuilderDirective
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'SDL'
"""
Filter a query by a nested key inside a JSON field containing a specific value.
"""
directive @whereJsonNestedKey(
  """
  The JSON field to filter by.
  """
  key: String!
  
  """
  The nested key to filter by.
  """
  nestedKey: String!
  
  """
  The value to filter by.
  """
  value: Mixed!
) on ARGUMENT_DEFINITION
SDL;
    }

    public function handleBuilder(
        QueryBuilder|EloquentBuilder|Relation $builder,
        mixed $value
    ): QueryBuilder|EloquentBuilder|Relation {
        $key = $this->directiveArgValue('key');
        $nestedKey = $value['nested_key'];
        $combinedKey = "$key->" . $nestedKey;

        return $builder->whereJsonContains($combinedKey, $value['value']);
    }
}
