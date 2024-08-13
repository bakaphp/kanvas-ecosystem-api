<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum WorkflowEnum: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case REGISTERED = 'registered';
    case ATTACH_FILE = 'attach-file';
    case USER_LOGIN = 'user-login';
    case USER_LOGOUT = 'user-logout';
    case AFTER_FORGOT_PASSWORD = 'after-forgot-password';
    case REQUEST_FORGOT_PASSWORD = 'request-forgot-password';
    case CREATE_CUSTOM_FIELD = 'create-custom-field';
    case CREATE_CUSTOM_FIELDS= 'create-custom-fields';
}
