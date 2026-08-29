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
    case PAYWAY = 'payway';
    case PLATE_RECOGNIZER = 'plate_recognizer';
    case MINDEE = 'mindee';
    case SALESASSIST = 'salesassist';
    case ECHO_PAY = 'echo_pay';
    case AZUL = 'azul';
    case PLUSVAL = 'plusval';
    case MOVIPASS = 'movipass';
    case QUICKBOOKS = 'quickbooks';
    case OFAC = 'ofac';
    case TEE_TIME = 'teetime';
    case TWILIO = 'twilio';
    case MAILGUN = 'mailgun';
    case DEALERSOCKET = 'dealersocket';
    case SUPERCARROS = 'supercarros';
    case TOOKAN = 'tookan';
    case CHROMEDATA = 'chromedata';
    case TRIGGER_AI = 'trigger-ai';
    case RESPOND_IO = 'respond_io';
    case CALENDLY = 'calendly';
    case CARDNET = 'cardnet';
    case CONTACT_CHECKER = 'contact_checker';
    case OPENCLAW = 'openclaw';
    case HERMES = 'hermes';
    case MICROSOFT = 'microsoft';
    case INTRAS = 'intras';
    case LICENSE_PLATE_EXTRACTOR = 'license_plate_extractor';
    case LENDFLOW = 'lendflow';
    case PRODUCT_ENRICHMENT = 'product_enrichment';
    case REYNOLDS = 'reynolds';
    case ACUMATICA = 'acumatica';
    case MERCURY = 'mercury';
    case SALESFORCE = 'salesforce';
    case PIDEV = 'pidev';
    case WORDPRESS = 'wordpress';
    case UNIVERSAL_SEGUROS = 'universal_seguros';
    case YUSEN = 'yusen';
    case SLACK = 'slack';
    case TRELLO = 'trello';
    case JIRA = 'jira';
}
