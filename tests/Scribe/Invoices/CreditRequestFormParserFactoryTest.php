<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Connectors\Nzxt\Services\CreditRequestFormParserService as NzxtCreditRequestFormParserService;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;
use Kanvas\Scribe\Invoices\Services\CreditRequestFormParserFactory;
use Tests\Scribe\ScribeTestCase;

final class CreditRequestFormParserFactoryTest extends ScribeTestCase
{
    public function test_defaults_to_nzxt_when_unconfigured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, '');

        $parser = CreditRequestFormParserFactory::forApp($this->kanvasApp);

        $this->assertInstanceOf(NzxtCreditRequestFormParserService::class, $parser);
    }

    public function test_resolves_nzxt_when_explicitly_configured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'nzxt');

        $parser = CreditRequestFormParserFactory::forApp($this->kanvasApp);

        $this->assertInstanceOf(NzxtCreditRequestFormParserService::class, $parser);
    }

    public function test_falls_back_to_nzxt_for_an_unknown_configured_client(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'some-future-client-not-built-yet');

        $parser = CreditRequestFormParserFactory::forApp($this->kanvasApp);

        $this->assertInstanceOf(NzxtCreditRequestFormParserService::class, $parser);
    }
}
