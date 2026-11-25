<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractCreditRequestFormTool;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;
use Tests\Scribe\ScribeTestCase;

final class ExtractCreditRequestFormToolTest extends ScribeTestCase
{
    private mixed $originalClientConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        // App settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalClientConfig = $this->kanvasApp->get(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, $this->originalClientConfig);

        parent::tearDown();
    }

    public function test_points_the_agent_to_draft_credit_note_when_the_app_has_no_form_format(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, '');

        $result = new ExtractCreditRequestFormTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(filesystem_id: 1);

        $this->assertFalse($result['success']);
        $this->assertSame('no_form_format_configured', $result['reason']);
        $this->assertStringContainsString('draft_credit_note', $result['message']);
    }

    public function test_reports_a_missing_file_once_a_format_is_configured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CREDIT_REQUEST_FORM_CLIENT->value, 'nzxt');

        $result = new ExtractCreditRequestFormTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(filesystem_id: 999999999);

        $this->assertFalse($result['success']);
        $this->assertSame('file_not_found', $result['reason']);
    }
}
