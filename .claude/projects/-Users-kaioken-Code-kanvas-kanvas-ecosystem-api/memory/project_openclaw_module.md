---
name: OpenClaw Connector Module - Complete Reference
description: Full architecture reference for the OpenClaw connector that deploys AI agents to remote machines via SSH+Docker. Covers two deployment models (legacy CLI and Docker isolation), all actions, services, GraphQL API, and supported LLM providers.
type: project
---

## OpenClaw Connector Module

**Purpose:** Deploy and manage AI agents on remote machines via SSH, running inside Docker containers with the OpenClaw runtime. Supports Slack channel integration, LLM provider auth, usage/health monitoring, and real-time deployment status broadcasting.

**Why:** Kanvas agents need to run as always-on services (Slack bots, gateway-accessible endpoints) on dedicated infrastructure, isolated per-agent with their own Linux users, Docker containers, and port pairs.

### Two Deployment Models

1. **Legacy CLI model** (`DeployAgentAction`, `RemoveAgentAction`, `UpdateAgentDeploymentAction`) — chat path removed 2026-04-23, remaining deploy/remove/update still present for pre-Docker customers
   - SSH creds stored on **company** custom fields
   - Uses `SshClient(app, company)` constructor
   - Runs `openclaw agents add/delete/bind` CLI commands directly on the host
   - Workspace files written via SFTP (`writeFile`)

2. **Docker isolation model** (current, sole chat path — `LaunchAgentOnMachineAction`, `TerminateAgentOnMachineAction`, `ChatWithAgentAction`)
   - SSH creds stored on **AgentMachine** model
   - Uses `SshClient::fromMachine(machine)` factory
   - Creates dedicated Linux user (`agent-{slug}`), home directory, Docker containers
   - Files written via `base64 + sudo tee` then `chown 1000:1000` (node user in container)
   - Each agent gets: gateway port + proxy port pair from machine's range

### Key Models (Intelligence domain, `intelligence` DB)

- **AgentMachine** — remote server with SSH creds, port range (20000-30000), max_agents capacity
  - `allocatePortPair()` — finds next unused port pair
  - `hasCapacity()` — checks active deployments < max_agents
  - `activeDeployments()` — HasMany filtered by status=running

- **AgentDeployment** — links Agent to Machine with: system_user, home_directory, gateway_port, proxy_port, container_name, status
  - Status lifecycle: `provisioning` -> `running` | `failed` | `stopped` -> `terminated`
  - `isRunning()` — checks status === 'running'

- **AgentUsageSnapshot** — token usage data collected from containers

### Deployment Lifecycle (Docker model)

1. **GraphQL `launchAgent(agent_id, machine_id)`** -> `AgentDeploymentMutation@launch`
2. -> `DispatchAgentDeploymentAction`: creates/reuses AgentDeployment record (provisioning), allocates ports, dispatches `LaunchAgentJob`
3. -> `LaunchAgentJob` (queued): calls `LaunchAgentOnMachineAction`
4. -> `LaunchAgentOnMachineAction`:
   - `provisionLinuxUser()` — `useradd`, add to docker group, create dirs
   - `writeDeploymentFiles()` — Dockerfile, docker-compose.yml, openclaw.json, auth-profiles.json, workspace files (SOUL.md, AGENTS.md, IDENTITY.md, USER.md, TOOLS.md)
   - `buildAndStart()` — `docker compose up -d --build`
   - Updates deployment status to `running`
5. -> `AgentDeploymentStatusChanged` event (Pusher broadcast on `company-{id}-app-{id}-deployments`)

### Termination

1. `terminateAgent(deployment_id)` -> dispatches `TerminateAgentJob`
2. -> `TerminateAgentOnMachineAction`: `docker compose down --rmi local` + `userdel -r` + mark terminated

### Container Architecture (docker-compose.yml)

Three services per agent:
- **openclaw-gateway** — main container, runs `gateway` command, exposes ports (gateway:18789, proxy:18790)
- **socat-proxy** — Alpine socat, forwards proxy port to gateway (network_mode: service:openclaw-gateway)
- **openclaw-cli** — CLI container for `docker exec` commands, profile `cli` (not started by default)

Base image: `ghcr.io/phioranex/openclaw-docker:latest`
Version tracked: `DockerComposeBuilder::OPENCLAW_VERSION = '2026.3.12'`

### Configuration Files Generated

1. **openclaw.json** — agent config with:
   - Model defaults (primary + fallbacks, currently google/gemini-3.1-pro-preview)
   - Agent list with workspace/agentDir paths
   - Tools config (coding profile, elevated permissions for slack)
   - Gateway config (port 18789, token auth, loopback)
   - Channel config (Slack socket mode if tokens provided)
   - Hooks (boot-md, session-memory)
   - Web search (Gemini-powered if API key set)

2. **auth-profiles.json** — LLM provider API keys:
   - Google (google:default) — from `ConfigurationEnum::GOOGLE_API_KEY`
   - Anthropic (anthropic:default) — from `ConfigurationEnum::ANTHROPIC_API_KEY`

3. **Workspace files** (via `WorkspaceFileBuilder`):
   - `SOUL.md` — from Agent::soul or Agent::role['background'] + output_format
   - `AGENTS.md` — from Agent::instructions or Agent::role['steps']
   - `IDENTITY.md` — name, creature, vibe, emoji, avatar from Agent::identity
   - `USER.md` — optional, from Agent::user_context
   - `TOOLS.md` — optional, from Agent::tools_config

### Chat (Docker model)

`ChatWithAgentAction`: SSH into machine, then `docker exec {container} curl http://127.0.0.1:18789/v1/responses` with `Authorization: Bearer <token>` and `x-openclaw-session-key: agent:<slug>:<key>` headers. Gateway token lives on the `AgentDeployment` as custom field `OPENCLAW_GATEWAY_TOKEN` (captured at deploy, lazy-fetched for pre-existing deployments via `FetchGatewayTokenAction`). 150s timeout for the SSH exec.

**Why HTTP not CLI:** `--session-id` and `--to` CLI flags are documented but broken — both collapse to `:main`, preventing per-channel session isolation (upstream bugs #22085, #36401, closed as not planned). `x-openclaw-session-key` HTTP header is the only working primitive for arbitrary session keys. Gateway stays on container loopback (127.0.0.1:18789) — never exposed publicly. We reach it through the same SSH + `docker exec` transport used for deploy.

### GraphQL API (`openclaw.graphql`)

**Queries** (`@guardByAdmin`):
- `agentUsageSnapshots` — paginated usage data
- `agentMachines` — paginated machine list
- `agentDeployments` — paginated deployment list

**Mutations** (`@guardByAdmin`):
- Machine CRUD: `createAgentMachine`, `updateAgentMachine`, `deleteAgentMachine`
- Deployment lifecycle: `launchAgent`, `terminateAgent`, `restartAgentContainer`
- Monitoring: `agentContainerLogs`, `agentContainerStatus`, `collectDeploymentUsage`
- Config: `setAgentSlackTokens(agent_id, slack_bot_token, slack_app_token)`

### Configuration Enums

**ConfigurationEnum** (app/company settings):
- SSH: `ssh_host`, `ssh_port`, `ssh_user`, `ssh_private_key` (legacy model)
- OpenClaw: `openclaw_home`, `cli_path`, `config_filename`
- Keys: `gateway_token`, `gemini_api_key`, `google_api_key`, `anthropic_api_key`
- Defaults: `default_environment`, `default_machine_id`, `default_model`
- Template: `dockerfile_template` (app-level override)

**CustomFieldEnum** (agent-level):
- `OPENCLAW_AGENT_ID`, `OPENCLAW_WORKSPACE_PATH`, `OPENCLAW_DEPLOYMENT_STATUS`, `OPENCLAW_DEPLOYMENT_ID`
- `OPENCLAW_SLACK_BOT_TOKEN`, `OPENCLAW_SLACK_APP_TOKEN`

**DeploymentStatusEnum**: pending, provisioning, running, stopped, failed, terminated

### Handler (Setup)

`OpenClawHandler::setup()` — creates AgentMachine from SSH creds, validates connectivity via `echo ok`, stores default_machine_id and gateway_token on company

### OpenClaw Providers (from docs.openclaw.ai)

OpenClaw supports 25+ LLM providers:
- **Major cloud**: Anthropic, OpenAI, Google (Gemini), AWS Bedrock
- **Specialized inference**: Groq, Together AI, OpenRouter, NVIDIA
- **Local runners**: Ollama, vLLM, SGLang
- **Gateway/proxy**: LiteLLM, OpenRouter, Vercel AI Gateway, Cloudflare AI Gateway
- **Regional**: Qianfan, Moonshot AI, Volcengine, Qwen, Xiaomi
- **Other**: Mistral, Perplexity, Venice, MiniMax, xAI, Hugging Face
- **Transcription**: Deepgram

Model format: `provider/model-name` (e.g., `google/gemini-3.1-pro-preview`, `anthropic/claude-opus-4-6`)
Auth: via `openclaw onboard` or auth-profiles.json with API keys

### Usage Collection

- `CollectDeploymentUsageAction` — runs `status --usage --json` inside container, parses session-level token data (input/output/cache/model), stores in AgentUsageSnapshot
- `CollectDailyUsageAction` — legacy CLI `status --usage` (text output, parsed by provider)
- `CollectHealthSnapshotAction` — `health --json` via legacy CLI
- `CollectDeploymentUsageJob` — queued version of CollectDeploymentUsageAction

### How to apply

When working on OpenClaw features, use the Docker isolation model (AgentMachine + AgentDeployment) as the primary pattern. The legacy CLI model exists but is being phased out. All new features should use `SshClient::fromMachine()` and the deployment lifecycle.
