<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum CrmPeopleMatchEnum: string
{
    case CLIENT_ID = 'client_id';
    case NAME_AND_CONTACT = 'name_and_contact';
}
