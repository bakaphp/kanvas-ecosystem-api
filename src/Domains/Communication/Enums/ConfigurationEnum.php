<?php

declare(strict_types=1);

namespace Kanvas\Communication\Enums;

enum ConfigurationEnum: string
{
    /**
     * Provider slugs an app or a company lets this domain provision, for the providers
     * `config('communication.opt_in')` holds back. Stored as a list, e.g. `["twilio"]`.
     */
    case PROVIDERS = 'communication_providers';
}
