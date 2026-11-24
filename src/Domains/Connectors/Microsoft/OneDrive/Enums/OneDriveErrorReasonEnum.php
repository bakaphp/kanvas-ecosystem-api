<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Enums;

enum OneDriveErrorReasonEnum: string
{
    case NOT_CONNECTED = 'microsoft_not_connected';
    case PERMISSION_MISSING = 'microsoft_files_permission_missing';
    case NOT_FOUND = 'not_found';
    case FILE_CHANGED = 'file_changed';
    case FILE_LOCKED = 'file_locked';
    case ONEDRIVE_NOT_AVAILABLE = 'onedrive_not_available';
    case OUTSIDE_ALLOWED_FOLDER = 'outside_allowed_folder';
    case NAME_CONFLICT = 'name_conflict';
    case TEXT_NOT_FOUND = 'text_not_found';
    case UNSUPPORTED_TYPE = 'unsupported_type';
    case TOO_LARGE = 'too_large';
    case REQUEST_FAILED = 'request_failed';
}
