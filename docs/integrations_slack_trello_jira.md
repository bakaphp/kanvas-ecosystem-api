# Slack, Trello & Jira Integrations

This document explains how the Slack, Trello and Jira connectors are wired into Kanvas, how to
enable/configure each one per company, and how to use them from workflow rules to automate
notifications and team synchronization.

All three follow the same [connector pattern](../src/Domains/Connectors/CLAUDE.md) used across the
codebase (Shopify, Stripe, WordPress, Salesforce, pi.dev, ...):

```
src/Domains/Connectors/{Slack,Trello,Jira}/
├── Client.php                 # Thin REST client (Trello/Jira) — Slack reuses the existing bot-token Client
├── Handlers/*Handler.php      # Extends BaseIntegration; validates + stores credentials
├── Services/*.php             # Business logic on top of the Client
├── Activities/*.php           # #[WorkflowAction] steps a rule can run
└── Enums/ConfigurationEnum.php, CustomFieldEnum.php
```

Each connector is registered as a row in the generic `integrations` table (see
`database/migrations/Workflow/2026_08_28_*_integration.php`), which means credentials are connected,
configured and tested through the **same GraphQL mutation every other connector uses** —
`integrationCompany` — no bespoke GraphQL was added for setup. Removing/disabling a connection also
reuses the generic `removeIntegrationCompany` / `integrationCompanyIsActive` mutations.

Once connected, each integration exposes one or more workflow actions
(`#[Kanvas\Workflow\Attributes\WorkflowAction]`) that are auto-discovered by
`php artisan kanvas:workflow-sync-actions` and become selectable steps in the Workflow Rules UI /
`actions` GraphQL query — no manual registration needed.

---

## 1. Slack — send notifications

Kanvas already ships a full Slack **agent** integration (two-way conversational channel, see
`src/Domains/Connectors/Slack/Actions/ConnectSlackAgentAction.php`). The pieces documented here are
a separate, much smaller capability: **posting one-way notifications** to a Slack channel from any
workflow rule (new order, failed sync, escalated lead, etc.) — either via an **Incoming Webhook** or
a **Bot User OAuth Token**.

* Handler: `Kanvas\Connectors\Slack\Handlers\SlackNotificationHandler`
* Service: `Kanvas\Connectors\Slack\Services\SlackNotificationService`
* Workflow action: `Kanvas\Connectors\Slack\Activities\SendSlackNotificationActivity`
* Config keys: `Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum`
* Integration name: `slack` (`Kanvas\Workflow\Enums\IntegrationsEnum::SLACK`)

### Configure

At least one of `webhook_url` / `bot_token` is required. Both may be set — a rule can then choose a
channel per call via the bot token while the webhook stays a fallback for its own single channel.

```graphql
mutation {
  integrationCompany(input: {
    integration: { name: "slack" }
    region: { id: "<region id>" }
    company_id: "<company id>"
    config: {
      webhook_url: "https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXX"
      bot_token: "xoxb-your-bot-token"
      default_channel: "#alerts"
    }
  }) { id }
}
```

* `webhook_url` — from Slack: **Apps → Incoming Webhooks → Add New Webhook to Workspace**.
* `bot_token` — from a Slack app's **OAuth & Permissions** page (needs the `chat:write` scope), a
  Bot User OAuth Token (`xoxb-...`).
* `default_channel` — used when a rule doesn't pass one explicitly (only consulted for the bot-token
  path — a webhook is already bound to a single channel on Slack's side).

`SlackNotificationHandler::setup()` posts a canned test message (webhook) and/or calls
`auth.test` (bot token) before storing anything, so a bad credential fails the mutation instead of
the first real alert.

### Use from a workflow rule

Attach the **"Send Slack Notification"** action to a rule. Params:

| param | required | description |
|---|---|---|
| `text` | yes | Message body to post |
| `channel` | no | Channel id/name; only used with a bot token, falls back to `default_channel` |

### Send it directly (no rule)

```php
use Kanvas\Connectors\Slack\Services\SlackNotificationService;

$service = new SlackNotificationService($app, $company);
$service->send('Deploy finished ✅', '#deploys');
```

---

## 2. Trello — cards, lists & boards

* Client: `Kanvas\Connectors\Trello\Client`
* Handler: `Kanvas\Connectors\Trello\Handlers\TrelloHandler`
* Service: `Kanvas\Connectors\Trello\Services\TrelloBoardService`
* Workflow action: `Kanvas\Connectors\Trello\Activities\CreateTrelloCardActivity`
* Config keys: `Kanvas\Connectors\Trello\Enums\ConfigurationEnum`
* Entity linkage: `Kanvas\Connectors\Trello\Enums\CustomFieldEnum::TRELLO_CARD_ID`
* Integration name: `trello`

### Configure

```graphql
mutation {
  integrationCompany(input: {
    integration: { name: "trello" }
    region: { id: "<region id>" }
    company_id: "<company id>"
    config: {
      api_key: "<Trello developer API key>"
      api_token: "<Trello user token>"
    }
  }) { id }
}
```

* `api_key` — from https://trello.com/app-key (per Trello Power-Up/app).
* `api_token` — the user token generated on the same page (or via the OAuth authorize link Trello
  gives you), scoped to whatever boards that member can see.

`TrelloHandler::setup()` calls `GET /1/members/me` with the pair before storing it.

### Use from a workflow rule

Attach **"Create Trello Card"**. Params:

| param | required | description |
|---|---|---|
| `list_id` | yes | Trello list id (`idList`) the card is created under |
| `name` | yes | Card title |
| `description` | no | Card description (Markdown) |
| `due` | no | ISO-8601 due date |

If the entity already has a card linked (`TRELLO_CARD_ID` custom field, set on the first run), the
activity **updates that card** instead of creating a duplicate — so re-running the rule (a retry, a
second webhook delivery, ...) is safe.

### Use the service directly

```php
use Kanvas\Connectors\Trello\Services\TrelloBoardService;

$trello = TrelloBoardService::forApp($app, $company);
$boards = $trello->boards();
$lists = $trello->lists($boards[0]['id']);
$card = $trello->createCard($lists[0]['id'], 'Investigate outage', 'Customer reported downtime');
$trello->addComment($card['id'], 'Looking into this now.');
```

---

## 3. Jira — issues, transitions & worklogs

* Client: `Kanvas\Connectors\Jira\Client`
* Handler: `Kanvas\Connectors\Jira\Handlers\JiraHandler`
* Service: `Kanvas\Connectors\Jira\Services\JiraIssueService`
* Workflow action: `Kanvas\Connectors\Jira\Activities\CreateJiraIssueActivity`
* Config keys: `Kanvas\Connectors\Jira\Enums\ConfigurationEnum`
* Entity linkage: `Kanvas\Connectors\Jira\Enums\CustomFieldEnum::JIRA_ISSUE_KEY`
* Integration name: `jira`

### Configure

```graphql
mutation {
  integrationCompany(input: {
    integration: { name: "jira" }
    region: { id: "<region id>" }
    company_id: "<company id>"
    config: {
      instance_url: "https://your-domain.atlassian.net"
      email: "agent@yourcompany.com"
      api_token: "<Jira API token>"
      default_project_key: "OPS"
      default_issue_type: "Task"
    }
  }) { id }
}
```

* `instance_url` — your Jira Cloud site, e.g. `https://your-domain.atlassian.net`.
* `email` — the Atlassian account email the token belongs to.
* `api_token` — from https://id.atlassian.com/manage-profile/security/api-tokens. Jira Cloud
  authenticates with HTTP Basic auth (`email:api_token`) — there is no OAuth refresh to manage.
* `default_project_key` / `default_issue_type` — optional, stored for future use by callers that
  don't want to pass them on every request.

`JiraHandler::setup()` calls `GET /rest/api/3/myself` before storing anything.

### Use from a workflow rule

Attach **"Create Jira Issue"**. Params:

| param | required | description |
|---|---|---|
| `project_key` | yes | Jira project key, e.g. `OPS` |
| `summary` | yes | Issue summary/title |
| `description` | no | Plain text — automatically wrapped into Jira's Atlassian Document Format |
| `issue_type` | no | Issue type name (`Task`, `Bug`, `Story`, ...). Defaults to `Task` |

Same idempotency shape as Trello: a re-run updates the linked issue (`JIRA_ISSUE_KEY` custom field)
instead of filing a duplicate.

### Use the service directly

```php
use Kanvas\Connectors\Jira\Services\JiraIssueService;

$jira = JiraIssueService::forApp($app, $company);
$issue = $jira->createIssue('OPS', 'Investigate outage', 'Customer reported downtime');
$jira->transitionIssue($issue['key'], 'In Progress');
$jira->addWorklog($issue['key'], '2h', 'Investigated root cause');
$jira->transitionIssue($issue['key'], 'Done', 'Resolved — see comments.');
```

`transitionIssue()` takes the target status by **name** (e.g. `"In Progress"`, `"Done"`) rather than
Jira's numeric transition id, since transition ids are workflow-specific per Jira project and a rule
author can't be expected to know them; it looks the id up via `GET /issue/{key}/transitions` and
raises a clear error if that status isn't reachable from the issue's current one.

---

## Removing / testing a connection

These reuse the generic mutations already used by every connector:

```graphql
mutation { removeIntegrationCompany(id: "<integration_companies.id>") }

mutation {
  integrationCompanyIsActive(input: { id: "<integration_companies.id>", is_active: false })
}
```

There is no separate "test connection" mutation: `integrationCompany` **is** the test — the handler
validates the credentials against the real API before the row is marked `ACTIVE` (or `FAILED` if
validation throws).

## Tests

* `tests/Connectors/Slack/SlackNotificationServiceTest.php`,
  `SlackNotificationHandlerTest.php` — unit tests for the notification service and handler
  (webhook + bot token paths), using `Http::fake()`.
* `tests/Connectors/Trello/ClientTest.php`, `TrelloBoardServiceTest.php`, `TrelloHandlerTest.php`
* `tests/Connectors/Jira/ClientTest.php`, `JiraIssueServiceTest.php`, `JiraHandlerTest.php`
* `tests/Connectors/Integration/{Slack,Trello,Jira}/*ActivityTest.php` — end-to-end through
  `executeIntegration()` (registers a real `integrations` / `integration_companies` row via the
  `HasIntegrationCompany` test trait, then runs the `KanvasActivity`).

## Adding another operation

Follow the existing `Services/*.php` classes as the extension point — e.g. add
`JiraIssueService::assignIssue()` or `TrelloBoardService::addMemberToCard()`, then wrap it in a new
`#[WorkflowAction]`-tagged class under `Activities/` if it should be callable from a rule. See
`.claude/skills/kanvas-connector/SKILL.md` for the full scaffold checklist.
