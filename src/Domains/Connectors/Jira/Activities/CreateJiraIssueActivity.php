<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jira\Enums\CustomFieldEnum;
use Kanvas\Connectors\Jira\Services\JiraIssueService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Files (or, on a re-run, updates) a Jira issue from any Kanvas entity. The entity keeps the
 * created issue key in its custom fields (`CustomFieldEnum::JIRA_ISSUE_KEY`), the same
 * idempotency shape `Trello\Activities\CreateTrelloCardActivity` uses for its card id.
 */
#[WorkflowAction(
    name: 'Create Jira Issue',
    description: 'Creates a Jira issue in the given project from a Kanvas entity. If the entity '
        . 'already has an issue linked (from a previous run), updates the summary/description on '
        . 'that issue instead of filing a duplicate.',
    integration: IntegrationsEnum::JIRA,
    requiresConfig: [
        ConfigurationEnum::INSTANCE_URL,
        ConfigurationEnum::EMAIL,
        ConfigurationEnum::API_TOKEN,
    ],
    requiredParams: ['project_key', 'summary'],
    params: [
        'project_key' => 'Jira project key the issue is filed under (e.g. "OPS"). Required.',
        'summary' => 'Issue summary/title. Required.',
        'description' => 'Issue description (plain text — wrapped into Jira\'s document format).',
        'issue_type' => 'Issue type name: Task, Bug, Story... Defaults to "Task".',
    ],
)]
class CreateJiraIssueActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $projectKey = trim((string) ($params['project_key'] ?? ''));
        $summary = trim((string) ($params['summary'] ?? ''));

        if ($projectKey === '' || $summary === '') {
            return $this->failWorkflow([
                'message' => 'Missing required params "project_key" and/or "summary"',
                'entity' => [get_class($entity), $entity->getId()],
            ]);
        }

        $company = $entity->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::JIRA,
            additionalParams: $params,
            integrationOperation: function (Model $entity, Apps $app, mixed $integrationCompany, array $additionalParams) use ($projectKey, $summary, $company): array {
                $service = JiraIssueService::forApp($app, $company);

                $description = isset($additionalParams['description']) ? (string) $additionalParams['description'] : null;
                $issueType = trim((string) ($additionalParams['issue_type'] ?? '')) ?: 'Task';

                $existingIssueKey = $this->existingIssueKey($entity);

                if ($existingIssueKey !== null) {
                    $service->updateIssue($existingIssueKey, array_filter([
                        'summary' => $summary,
                        'description' => $description,
                    ]));

                    return ['issue_key' => $existingIssueKey, 'created' => false];
                }

                $issue = $service->createIssue($projectKey, $summary, $description, $issueType);

                if (isset($issue['key']) && method_exists($entity, 'set')) {
                    $entity->set(CustomFieldEnum::JIRA_ISSUE_KEY->value, (string) $issue['key']);
                    $entity->set(CustomFieldEnum::JIRA_ISSUE_ID->value, (string) ($issue['id'] ?? ''));
                }

                return ['issue' => $issue, 'created' => true];
            },
            company: $company,
        );
    }

    private function existingIssueKey(Model $entity): ?string
    {
        if (! method_exists($entity, 'get')) {
            return null;
        }

        $issueKey = $entity->get(CustomFieldEnum::JIRA_ISSUE_KEY->value);

        return empty($issueKey) ? null : (string) $issueKey;
    }
}
