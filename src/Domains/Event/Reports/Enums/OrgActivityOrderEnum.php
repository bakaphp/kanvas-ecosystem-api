<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Enums;

enum OrgActivityOrderEnum: string
{
    case COUNT_DESC = 'count_desc';
    case COUNT_ASC = 'count_asc';
    case LAST_DATE_DESC = 'last_date_desc';
    case FIRST_DATE_ASC = 'first_date_asc';
    case NAME_ASC = 'name_asc';
}
