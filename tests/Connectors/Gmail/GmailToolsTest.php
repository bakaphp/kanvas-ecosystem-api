<?php

declare(strict_types=1);

namespace Tests\Connectors\Gmail;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Gmail\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\DownloadAttachmentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ListEmailsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\MarkEmailAsReadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReadEmailDetailsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReplyToEmailTool;
use Tests\TestCase;

class GmailToolsTest extends TestCase
{
    use DatabaseTransactions;

    private mixed $originalClientId = null;
    private mixed $originalClientSecret = null;
    private mixed $originalRefreshToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $this->originalClientId = $app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->originalClientSecret = $app->get(ConfigurationEnum::CLIENT_SECRET->value);
        $this->originalRefreshToken = $app->get(ConfigurationEnum::REFRESH_TOKEN->value);

        $app->set(ConfigurationEnum::CLIENT_ID->value, '');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, '');
        $app->set(ConfigurationEnum::REFRESH_TOKEN->value, '');
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CLIENT_ID->value, $this->originalClientId);
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, $this->originalClientSecret);
        $app->set(ConfigurationEnum::REFRESH_TOKEN->value, $this->originalRefreshToken);

        parent::tearDown();
    }

    public function test_list_emails_surfaces_a_humanized_error_when_gmail_is_not_configured(): void
    {
        [$app, $company] = $this->context();

        $result = new ListEmailsTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(query: 'has:attachment is:unread');

        $this->assertFalse($result['success']);
        $this->assertSame('list_failed', $result['reason']);
    }

    public function test_read_email_details_surfaces_a_humanized_error_when_gmail_is_not_configured(): void
    {
        [$app, $company] = $this->context();

        $result = new ReadEmailDetailsTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(message_id: 'MSG_1');

        $this->assertFalse($result['success']);
        $this->assertSame('read_failed', $result['reason']);
    }

    public function test_download_attachment_surfaces_a_humanized_error_when_gmail_is_not_configured(): void
    {
        [$app, $company] = $this->context();

        $result = new DownloadAttachmentTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(message_id: 'MSG_1', attachment_id: 'ATTACH_1', filename: 'invoice.pdf');

        $this->assertFalse($result['success']);
        $this->assertSame('download_failed', $result['reason']);
    }

    public function test_mark_email_as_read_surfaces_a_humanized_error_when_gmail_is_not_configured(): void
    {
        [$app, $company] = $this->context();

        $result = new MarkEmailAsReadTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(message_id: 'MSG_1');

        $this->assertFalse($result['success']);
        $this->assertSame('mark_read_failed', $result['reason']);
    }

    public function test_reply_to_email_reports_no_approver_configured_when_missing(): void
    {
        [$app, $company] = $this->context();

        $result = new ReplyToEmailTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(message_id: 'MSG_1', note: 'Approved by Jane Doe on 2026-08-19', target_type: 'bill', target_id: 999999999);

        $this->assertFalse($result['replied']);
        $this->assertSame('no_approver_configured', $result['reason']);
    }

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        return [$app, $company];
    }
}
