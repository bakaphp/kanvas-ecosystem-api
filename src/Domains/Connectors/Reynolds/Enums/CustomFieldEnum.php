<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Enums;

enum CustomFieldEnum: string
{
    case PROSPECT_ID = 'REYNOLDS_PROSPECT_ID';
    case NAME_REC_ID = 'REYNOLDS_NAME_REC_ID';
    case PROSPECT_TYPE = 'REYNOLDS_PROSPECT_TYPE';
    case PROSPECT_STATUS = 'REYNOLDS_PROSPECT_STATUS';
    case PROSPECT_STATUS_TYPE = 'REYNOLDS_PROSPECT_STATUS_TYPE';
    case PROSPECT_NOTE = 'REYNOLDS_PROSPECT_NOTE';
    case PROVIDER_NAME = 'REYNOLDS_PROVIDER_NAME';
    case PROVIDER_SERVICE = 'REYNOLDS_PROVIDER_SERVICE';
    case IS_AI_GENERATED = 'REYNOLDS_IS_AI_GENERATED';
    case IS_CI_LEAD = 'REYNOLDS_IS_CI_LEAD';

    // Shared Kanvas custom-field keys — matches what every other CRM connector
    // (VinSolution, DriveCentric, DealerSocket, SalesAssist) writes, so the AI
    // tooling picks them up automatically without per-connector wiring. Trade-in
    // must be `vehicle_trade_id` (not `trade_in`) for VehicleTradeInTool to read it.
    case VEHICLE_OF_INTEREST = 'vehicle_of_interest';
    case TRADE_IN = 'vehicle_trade_id';

    // Trade-in submitted via SalesAssist. Reynolds USL has no trade sub-flow,
    // so the form is stored on the lead and imported into the DMS by the
    // SalesAssist extension. `tradein_data` holds the raw form; `tradein_imported`
    // holds the {active, message, date} importer banner metadata.
    case TRADE_IN_DATA = 'tradein_data';
    case TRADE_IN_IMPORTED = 'tradein_imported';

    case CONSENT_EMAIL = 'REYNOLDS_CONSENT_EMAIL';
    case CONSENT_TEXT = 'REYNOLDS_CONSENT_TEXT';
    case CONSENT_PHONE = 'REYNOLDS_CONSENT_PHONE';
    case CONSENT_MAIL = 'REYNOLDS_CONSENT_MAIL';
    case CONSENT_OPT_OUT = 'REYNOLDS_CONSENT_OPT_OUT';
    case CONSENT_OPT_OUT_USE = 'REYNOLDS_CONSENT_OPT_OUT_USE';
    case SEND_TEXTS_TO = 'REYNOLDS_SEND_TEXTS_TO';

    case PREFERRED_CONTACT = 'REYNOLDS_PREFERRED_CONTACT';
    case LANGUAGE = 'REYNOLDS_LANGUAGE';
    case CONTACT_TYPE = 'REYNOLDS_CONTACT_TYPE';
    case CLIENT_ID = 'REYNOLDS_CLIENT_ID';
}
