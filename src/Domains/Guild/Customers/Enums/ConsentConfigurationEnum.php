<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

/**
 * `do_not_contact` keeps its bare value: leads flagged by the agent tool before this enum existed
 * wrote that exact key, and SendEmailTool / SendSmsTool / BatchRecipientResolverService /
 * ProcessLeadCampaignJob all read it. Renaming it would silently un-flag every existing opt-out.
 */
enum ConsentConfigurationEnum: string
{
    case DO_NOT_CONTACT = 'do_not_contact';
    case DO_NOT_CONTACT_REASON = 'do_not_contact_reason';
    case DO_NOT_CONTACT_AT = 'do_not_contact_at';
    case DO_NOT_CONTACT_SOURCE = 'do_not_contact_source';
    case DO_NOT_CONTACT_MATCH = 'do_not_contact_match';
    case CONFIRMATION_MESSAGE = 'opt_out_confirmation_message';
    case CONFIRMATION_SENT_AT = 'opt_out_confirmation_sent_at';
}
