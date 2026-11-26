<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Baka\Support\AllowedClass;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Invoices\Contracts\CreditRequestFormParserInterface;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;

// Scribe never names a parser class: config('scribe.credit_request_form_parsers') maps the app setting to one, so a new format is a line there and nothing here.
final class CreditRequestFormParserFactory
{
    public static function forApp(Apps $app): ?CreditRequestFormParserInterface
    {
        $configured = $app->get(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value);
        $key = is_string($configured) ? Str::trimToNull(strtolower($configured)) : null;

        if ($key === null) {
            return null;
        }

        $class = AllowedClass::resolve(
            config("scribe.credit_request_form_parsers.{$key}"),
            CreditRequestFormParserInterface::class
        );

        return $class === null ? null : new $class();
    }
}
