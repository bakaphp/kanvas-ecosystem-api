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
    case UNIVERSAL_ASSISTANCE = 'universal_assistance';
    case WASENDER = 'wa_sender';
    case DRIVE_CENTRIC = 'drive_centric';
    case PASO_RAPIDO = 'paso_rapido';
    case PLATE_RECOGNIZER = 'plate_recognizer';
    case MINDEE = 'mindee';
    case SALESASSIST = 'salesassist';
    case ECHO_PAY = 'echo_pay';
    case PLUSVAL = 'plusval';
    case MOVIPASS = 'movipass';
    case QUICKBOOKS = 'quickbooks';
    case OFAC = 'ofac';
    case TEE_TIME = 'teetime';
    case TWILIO = 'twilio';
}
