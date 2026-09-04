<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Jira;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Jira\Activities\CreateJiraIssueActivity;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jira\Enums\CustomFieldEnum;
use Kanvas\Connectors\Jira\Handlers\JiraHandler;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class CreateJiraIssueActivityTest extends TestCase
{
    use HasIntegrationCompany;

    private const string INSTANCE_URL = 'https://kanvas.atlassian.net';

    public function testCreatesAnIssueAndLinksItToTheEntity(): void
    {
        $this->registerIntegration();
        $this->configureJira();

        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue' => Http::response(['id' => '10001', 'key' => 'OPS-1']),
        ]);

        $message = $this->makeMessage();

        $result = $this->activity()->execute($message, app(Apps::class), [
            'project_key' => 'OPS',
            'summary' => 'Customer reported an outage',
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame('OPS-1', $result['issue']['key']);
        $this->assertSame('OPS-1', $message->get(CustomFieldEnum::JIRA_ISSUE_KEY->value));
    }

    public function testUpdatesTheLinkedIssueInsteadOfFilingADuplicate(): void
    {
        $this->registerIntegration();
        $this->configureJira();

        $message = $this->makeMessage();
        $message->set(CustomFieldEnum::JIRA_ISSUE_KEY->value, 'OPS-1');

        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue/OPS-1' => Http::response('', 204),
        ]);

        $result = $this->activity()->execute($message, app(Apps::class), [
            'project_key' => 'OPS',
            'summary' => 'Updated summary',
        ]);

        $this->assertFalse($result['created']);
        $this->assertSame('OPS-1', $result['issue_key']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function testFailsTheWorkflowWhenRequiredParamsAreMissing(): void
    {
        $result = $this->activity()->execute($this->makeMessage(), app(Apps::class), []);

        $this->assertStringContainsString('project_key', $result['message']);
    }

    private function activity(): CreateJiraIssueActivity
    {
        return new CreateJiraIssueActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    private function registerIntegration(): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::JIRA,
            JiraHandler::class,
            $user->getCurrentCompany(),
            $user
        );
    }

    private function configureJira(): void
    {
        $this->company()->set(ConfigurationEnum::INSTANCE_URL->value, self::INSTANCE_URL);
        $this->company()->set(ConfigurationEnum::EMAIL->value, 'agent@kanvas.test');
        $this->company()->set(ConfigurationEnum::API_TOKEN->value, 'test-api-token');
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function makeMessage(): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['message' => ['content' => 'Customer reported an outage.']]);
    }
}
