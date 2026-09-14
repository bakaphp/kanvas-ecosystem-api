<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Enums;

enum CustomFieldEnum: string
{
    case ODOO_LEAD_ID = 'odoo_lead_id';
    case ODOO_CONTACT_ID = 'odoo_contact_id';
    case ODOO_ACCOUNT_ID = 'odoo_account_id';
}
