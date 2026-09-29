<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PriceDisclosure\Enums;

enum PriceDisclosureConfigurationEnum: string
{
    /** Company setting: the dealer is under the price-disclosure regime, so an unverified price never ships. */
    case ENABLED = 'price_disclosure_enabled';

    /** Lead custom field set by a suppression for the turn in progress; the reply gate clears it. */
    case REPLY_SUPPRESSED = 'agent_reply_suppressed';
}
