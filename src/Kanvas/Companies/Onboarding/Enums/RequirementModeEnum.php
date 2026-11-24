<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Enums;

enum RequirementModeEnum: string
{
    case CONNECT = 'connect';
    case REQUEST_SETUP = 'request_setup';
    case UPLOAD = 'upload';
    case KANVAS_PROVIDED = 'kanvas_provided';
    case WEBHOOK = 'webhook';
}
