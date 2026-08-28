<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Nzxt\Services\CreditRequestFormParserService;
use Kanvas\Scribe\Invoices\Contracts\CreditRequestFormParserInterface;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;
use Kanvas\Scribe\Invoices\Enums\CreditRequestFormClientEnum;

// Resolves which client's Credit Request Form parser an app uses. Adding a new client is exactly two edits: a case on CreditRequestFormClientEnum, and a match arm here — never a branch inside an existing client's parser.
final class CreditRequestFormParserFactory
{
    public static function forApp(Apps $app): CreditRequestFormParserInterface
    {
        $configured = $app->get(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value);
        $client = is_string($configured) ? CreditRequestFormClientEnum::tryFrom($configured) : null;

        return self::forClient($client ?? CreditRequestFormClientEnum::NZXT);
    }

    public static function forClient(CreditRequestFormClientEnum $client): CreditRequestFormParserInterface
    {
        return match ($client) {
            CreditRequestFormClientEnum::NZXT => new CreditRequestFormParserService(),
        };
    }
}
