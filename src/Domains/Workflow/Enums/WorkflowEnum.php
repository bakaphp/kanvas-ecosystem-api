<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

use InvalidArgumentException;

enum WorkflowEnum: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case REGISTERED = 'registered';
    case ATTACH_FILE = 'attach-file';
    case USER_LOGIN = 'user-login';
    case USER_LOGOUT = 'user-logout';
    case AFTER_FORGOT_PASSWORD = 'after-forgot-password';
    case AFTER_PUSH = 'after-push';
    case AFTER_PULL = 'after-pull';
    case REQUEST_FORGOT_PASSWORD = 'request-forgot-password';
    case CREATE_CUSTOM_FIELD = 'create-custom-field';
    case CREATE_CUSTOM_FIELDS = 'create-custom-fields';
    case SEARCH = 'search';
    case AFTER_PRODUCT_IMPORT = 'after-product-import';
    case SYNC_SHOPIFY = 'sync-shopify';
    case AFTER_CREATE_ORDER = 'after-create-order';

    /**
     * Get the enum case by its value.
     */
    public static function fromString(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new InvalidArgumentException("No WorkflowEnum case found for value: {$value}");
    }
}
