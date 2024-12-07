<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;

class DecimalFormatDirective extends BaseDirective
{
    public static function definition(): string
    {
        return /* @lang GraphQL */ <<<GRAPHQL
"""
Format a number to two decimal places.
"""
directive @decimalFormat on FIELD_DEFINITION
GRAPHQL;
    }

    public function resolveField($rootValue, array $args, $context, ResolveInfo $resolveInfo)
    {
        $resolver = $this->directiveArgValue('resolver', null);

        $value = $resolver ? $resolver($rootValue, $args, $context, $resolveInfo) : $rootValue->{$resolveInfo->fieldName};

        return number_format((float) $value, 2, '.', '');
    }
}
