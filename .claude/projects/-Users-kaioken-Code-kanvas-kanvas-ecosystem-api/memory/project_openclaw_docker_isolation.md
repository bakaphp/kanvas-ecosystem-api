---
name: OpenClaw Docker Isolation Migration Plan
description: Plan to migrate OpenClaw agent deployment from shared CLI model to per-agent Docker isolation with own Linux users, containers, and ports. Includes SSH on agent_machines, Jobs+Pusher broadcasting, SSH+docker exec for chat.
type: project
---

## OpenClaw Docker Isolation Migration

### Status: Implementation in progress (2026-03-15)

### Plan file: `.claude/plans/cuddly-wobbling-thacker.md`

### Key Decisions
- SSH config lives on `agent_machines` table (not company custom fields)
- No `config/openclaw.php` — Dockerfile/compose templates in app settings DB
- Chat via SSH + `docker exec` (not HTTP gateway)
- Real-time via queued jobs + Pusher broadcasting (existing infra)
- No streaming — openclaw CLI doesn't support `--stream`
- Does NOT touch agent swarm system

### 8 Phases
1. Database: `agent_machines` + `agent_deployments` tables + models
2. Enums: `DeploymentStatusEnum`, update `ConfigurationEnum` + `CustomFieldEnum`
3. SSH Client `fromMachine()` factory + `DockerComposeBuilder` service
4. Actions: Launch, Terminate, Status, Restart, Logs, Chat (rewrite), Update (adapt)
5. Broadcasting: `AgentDeploymentStatusChanged` event + `LaunchAgentJob`/`TerminateAgentJob`/`RestartAgentContainerJob`
6. GraphQL: Machine CRUD + deployment lifecycle mutations + queries + subscription
7. Handler updates: `OpenClawHandler.setup()` + `OpenClawAgentHandler.chat()`
8. Tests: `AgentMachineCrudTest` + `AgentDeploymentTest`

### Broadcasting channels
- `company-{id}-app-{id}-deployments` → `deployment.status.changed`
- `agent-chat-{appId}-{companyId}-{sessionId}` → `agent.chat.response` (existing)
