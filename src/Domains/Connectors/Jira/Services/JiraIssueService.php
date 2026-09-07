<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Jira\Client;
use Kanvas\Connectors\Jira\Exceptions\JiraException;

/**
 * High-level Jira issue operations built on top of the raw `Client` — create/update, status
 * transitions, and work logging. Everything a "sync this to Jira" workflow rule needs.
 */
class JiraIssueService
{
    public function __construct(protected Client $client)
    {
    }

    public static function forApp(AppInterface $app, CompanyInterface $company): self
    {
        return new self(new Client($app, $company));
    }

    /**
     * @param array<string, mixed> $fields Extra Jira fields merged in verbatim (labels, priority,
     *                                     assignee, custom fields...).
     * @return array<string, mixed> The created issue (id, key, self)
     */
    public function createIssue(
        string $projectKey,
        string $summary,
        ?string $description = null,
        string $issueType = 'Task',
        array $fields = []
    ): array {
        $payload = [
            'fields' => array_merge($fields, array_filter([
                'project' => ['key' => $projectKey],
                'summary' => $summary,
                'issuetype' => ['name' => $issueType],
                'description' => $description !== null ? self::toDocumentFormat($description) : null,
            ])),
        ];

        return $this->client->post('issue', $payload);
    }

    /**
     * Jira's PUT /issue returns an empty 204 body on success — the caller only needs to know it
     * didn't throw.
     *
     * @param array<string, mixed> $fields
     */
    public function updateIssue(string $issueIdOrKey, array $fields): void
    {
        $payload = array_filter([
            'summary' => $fields['summary'] ?? null,
            'description' => isset($fields['description']) ? self::toDocumentFormat((string) $fields['description']) : null,
        ]);

        unset($fields['summary'], $fields['description']);

        $this->client->put('issue/' . $issueIdOrKey, ['fields' => array_merge($fields, $payload)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getIssue(string $issueIdOrKey): array
    {
        return $this->client->get('issue/' . $issueIdOrKey);
    }

    /**
     * Available transitions from the issue's current status — the ids `transitionIssue()` needs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransitions(string $issueIdOrKey): array
    {
        return (array) ($this->client->get('issue/' . $issueIdOrKey . '/transitions')['transitions'] ?? []);
    }

    /**
     * Moves an issue to a new status by NAME (e.g. "In Progress", "Done") rather than by id,
     * since transition ids are workflow-specific and a rule author can't be expected to know them.
     */
    public function transitionIssue(string $issueIdOrKey, string $transitionName, ?string $comment = null): void
    {
        $transitionId = $this->resolveTransitionId($issueIdOrKey, $transitionName);

        $payload = ['transition' => ['id' => $transitionId]];

        if ($comment !== null && $comment !== '') {
            $payload['update'] = [
                'comment' => [
                    ['add' => ['body' => self::toDocumentFormat($comment)]],
                ],
            ];
        }

        $this->client->post('issue/' . $issueIdOrKey . '/transitions', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function addWorklog(string $issueIdOrKey, string $timeSpent, ?string $comment = null): array
    {
        return $this->client->post('issue/' . $issueIdOrKey . '/worklog', array_filter([
            'timeSpent' => $timeSpent,
            'comment' => $comment !== null ? self::toDocumentFormat($comment) : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function addComment(string $issueIdOrKey, string $comment): array
    {
        return $this->client->post('issue/' . $issueIdOrKey . '/comment', [
            'body' => self::toDocumentFormat($comment),
        ]);
    }

    protected function resolveTransitionId(string $issueIdOrKey, string $transitionName): string
    {
        foreach ($this->getTransitions($issueIdOrKey) as $transition) {
            $name = (string) ($transition['name'] ?? '');

            if (strcasecmp($name, $transitionName) === 0) {
                return (string) $transition['id'];
            }
        }

        throw new JiraException(
            'Jira issue ' . $issueIdOrKey . ' has no transition named "' . $transitionName . '" available from its current status.'
        );
    }

    /**
     * Jira Cloud API v3 requires rich-text fields (description, comment body...) in Atlassian
     * Document Format rather than plain strings. A single paragraph is enough for anything a
     * workflow rule composes — nobody is authoring rich text in a rule param.
     *
     * @return array<string, mixed>
     */
    public static function toDocumentFormat(string $text): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ],
            ],
        ];
    }
}
