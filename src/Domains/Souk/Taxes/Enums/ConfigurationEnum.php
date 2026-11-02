<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum ConfigurationEnum: string
{
    case TAX_ENGINE_ENABLED = 'souk_tax_engine_enabled';
    case TAX_FAIL_OPEN = 'souk_tax_fail_open';
    case SHIP_FROM = 'souk_tax_ship_from';
    case STRIPE_TAX_SAME_LEGAL_ENTITY_AS_APP_ACCOUNT = 'stripe_tax_same_legal_entity_as_app_account';
    case SCRIBE_SYNC_SOUK_ORDERS = 'scribe_sync_souk_orders';
}
