<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class RenameLeadSendToolsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['intelligence'];

    private const string MIGRATION = 'database/migrations/Intelligence/2026_10_09_000001_rename_lead_send_and_gmail_reply_tools.php';

    public function testRewritesOldToolIdsInAgentProseButNotInConfig(): void
    {
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'instructions' => 'Use send_email for the quote, send_sms for reminders, send_email_to_user for staff, reply_to_email on approvals.',
                'config' => ['send_email' => true],
            ]);

        $this->runMigration();

        $row = DB::connection('intelligence')->table('agents')->where('id', $agent->getId())->first();

        $this->assertSame(
            'Use send_lead_email for the quote, send_lead_sms for reminders, send_email_to_user for staff, gmail_reply_to_thread on approvals.',
            $row->instructions,
        );
        $this->assertStringContainsString('"send_email"', (string) $row->config);
    }

    public function testRefreshesTheCatalogLabelByHandler(): void
    {
        $tool = new Tool();
        $tool->apps_id = 0;
        $tool->name = 'Send Email';
        $tool->handler = SendEmailTool::class;
        $tool->tool_type = 'system';
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        $this->runMigration();

        $tool->refresh();
        $this->assertSame('Send Email To Lead', $tool->name);
        $this->assertStringStartsWith('LEADS ONLY', (string) $tool->description);
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }
}
