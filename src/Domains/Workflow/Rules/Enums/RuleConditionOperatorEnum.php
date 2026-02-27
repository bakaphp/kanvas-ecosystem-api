<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Enums;

enum RuleConditionOperatorEnum: string
{
    case EQUAL = '==';
    case NOT_EQUAL = '!=';
    case GREATER_THAN = '>';
    case GREATER_THAN_OR_EQUAL = '>=';
    case LESS_THAN = '<';
    case LESS_THAN_OR_EQUAL = '<=';
    case IN = 'in';
    case NOT_IN = 'not in';
    case MATCHES = 'matches';
}
