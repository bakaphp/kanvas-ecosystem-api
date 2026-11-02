<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum SexEnum: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case UNSPECIFIED = 'unspecified';
}
