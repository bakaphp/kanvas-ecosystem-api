<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum IntegrationsEnum: string
{
    case SHOPIFY = 'shopify';
    case KANVAS = 'kanvas';
    case VIN_SOLUTION = 'vinsolution';
    case ELEAD = 'elead';
    case INTELLICHECK = 'intellicheck';
    case PROMPT_MINE = 'prompt_mine';
    case INTERNAL = 'internal';
    case APOLLO = 'apollo';
    case CREDIT700 = '700_credit';
    case IPLUS = 'iplus';
    case NETSUITE = 'netsuite';
    case OFFERLOGIX = 'offerlogix';
    case RECOMBEE = 'recombee';
    case ZOHO = 'zoho';
    case ESIM_SOLUTION = 'esim_solution';
    case STRIPE = 'stripe';
    case ESIM_VENTA_MOBILE = 'esim_ventamobile';
    case AERO_AMBULANCIA = 'aero_ambulancia';
}
