<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Connectors\Nzxt\Services\CreditRequestFormParserService as NzxtCreditRequestFormParserService;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;
use Kanvas\Scribe\Invoices\Services\CreditRequestFormParserFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\Scribe\ScribeTestCase;

#[Group('serial')]
final class CreditRequestFormParserFactoryTest extends ScribeTestCase
{
    private mixed $originalClientConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        // HasCustomFields writes through Redis, which DatabaseTransactions never rolls back —
        // save/restore explicitly so a value set here can't leak into the next test in the run.
        $this->originalClientConfig = $this->kanvasApp->get(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, $this->originalClientConfig);

        parent::tearDown();
    }

    public function test_has_no_parser_when_unconfigured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, '');

        $this->assertNull(CreditRequestFormParserFactory::forApp($this->kanvasApp));
    }

    public function test_resolves_nzxt_when_explicitly_configured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'nzxt');

        $parser = CreditRequestFormParserFactory::forApp($this->kanvasApp);

        $this->assertInstanceOf(NzxtCreditRequestFormParserService::class, $parser);
    }

    public function test_has_no_parser_for_an_unknown_configured_client(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'some-future-client-not-built-yet');

        $this->assertNull(CreditRequestFormParserFactory::forApp($this->kanvasApp));
    }
}
