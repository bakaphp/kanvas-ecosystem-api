<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums;

enum PersonCustomFieldEnum: string
{
    case USERNAME = 'whatsapp_username';
    case USER_ID = 'meta_user_id';
}
