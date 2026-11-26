<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;
use Kanvas\Scribe\Invoices\Services\CreditRequestFormParserFactory;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Tests\Scribe\Invoices\Stubs\FakeCreditRequestFormParser;
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

        config(['scribe.credit_request_form_parsers' => [
            'fake-format' => FakeCreditRequestFormParser::class,
            'not-a-parser' => stdClass::class,
        ]]);
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

    public function test_resolves_the_parser_registered_for_the_configured_key(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, ' Fake-Format ');

        $parser = CreditRequestFormParserFactory::forApp($this->kanvasApp);

        $this->assertInstanceOf(FakeCreditRequestFormParser::class, $parser);
    }

    public function test_has_no_parser_for_a_key_that_is_not_registered(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'some-future-client-not-built-yet');

        $this->assertNull(CreditRequestFormParserFactory::forApp($this->kanvasApp));
    }

    public function test_never_builds_a_registered_class_that_is_not_a_parser(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'not-a-parser');

        $this->assertNull(CreditRequestFormParserFactory::forApp($this->kanvasApp));
    }
}
