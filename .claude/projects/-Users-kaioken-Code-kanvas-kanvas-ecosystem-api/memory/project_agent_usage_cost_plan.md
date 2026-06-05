---
name: Agent Usage & Cost Monitoring Plan
description: Plan for tracking per-agent token usage, costs, daily aggregation, admin reporting endpoints, and agent self-awareness of monthly budget
type: project
---

## Agent Usage & Cost Monitoring Plan

### Current State

| What's Tracked | Where | Gap |
|---|---|---|
| `duration_ms`, `input_chars`, `output_chars` | `AgentPerformanceMetric` | No token counts or costs |
| Input/output text, context | `AgentHistory` | No token metadata |
| LLM model config (name, provider) | `AgentModel` | No pricing fields |
| Daily aggregated snapshots | `AgentUsageSnapshot` | Table exists but **unused** |
| Wallet system | `Souk\Wallet` | **Not integrated** with agents |

### What to Track Per Agent

**Per execution (granular):**
- `tokens_in` — input/prompt tokens
- `tokens_out` — output/completion tokens
- `total_tokens` — sum
- `cost_usd` — computed from model pricing × tokens
- `model_name` — which LLM was used
- `session_id` — to group multi-turn conversations

**Per day (aggregated):**
- Total interactions (executions)
- Total sessions (unique conversations)
- Total tokens (in + out)
- Total cost
- Average latency

**Per month (for agent self-awareness):**
- Monthly token budget vs actual
- Monthly cost budget vs actual
- Interaction count

---

### Changes Needed

#### 1. Add pricing to `AgentModel`

New migration adding columns to `agent_models`:
```
input_token_cost_per_1k  DECIMAL(10,6)  — e.g. 0.003 for GPT-4
output_token_cost_per_1k DECIMAL(10,6)  — e.g. 0.015 for GPT-4
```

#### 2. Capture Prism token metadata

In `AgentChatMutation.php` — after Prism returns a response, extract token usage from the response metadata:
```php
$response = $prism->generate();
$usage = $response->usage; // tokens_in, tokens_out
```

Pass these into `TrackAgentUsageAction` alongside the existing duration/chars data.

#### 3. Expand `AgentPerformanceMetric`

Add columns:
```
tokens_in       INT UNSIGNED
tokens_out      INT UNSIGNED
total_tokens    INT UNSIGNED
cost_usd        DECIMAL(12,8)
model_name      VARCHAR(100)
session_id      VARCHAR(255) NULLABLE
```

#### 4. Activate `AgentUsageSnapshot` for daily rollups

Create a scheduled command (`agent:aggregate-usage`) that runs nightly:
- Groups `AgentPerformanceMetric` by agent + date
- Inserts/updates `AgentUsageSnapshot` with daily totals
- Fields: `agent_id`, `date`, `total_interactions`, `total_sessions`, `tokens_in`, `tokens_out`, `total_cost_usd`, `avg_duration_ms`

#### 5. GraphQL reporting endpoints (Admin UI)

```graphql
type AgentUsageReport {
    agent: AgentAi!
    period_start: Date!
    period_end: Date!
    total_interactions: Int!
    total_sessions: Int!
    tokens_in: Int!
    tokens_out: Int!
    total_tokens: Int!
    total_cost_usd: Float!
    avg_duration_ms: Float!
    daily_breakdown: [AgentDailyUsage!]!
}

type AgentDailyUsage {
    date: Date!
    interactions: Int!
    tokens_in: Int!
    tokens_out: Int!
    cost_usd: Float!
}

extend type Query @guardByAdmin {
    agentUsageReport(
        agent_id: ID!
        period_start: Date!
        period_end: Date!
    ): AgentUsageReport!

    agentsUsageSummary(
        period_start: Date!
        period_end: Date!
        orderBy: _ @orderBy(columns: ["total_cost_usd", "total_tokens", "total_interactions"])
    ): [AgentUsageReport!]! @paginate(defaultCount: 25)
}
```

#### 6. Agent self-awareness endpoint

```graphql
extend type Query @guard {
    myAgentUsage(agent_id: ID!): AgentMonthlyUsage!
}

type AgentMonthlyUsage {
    current_month_tokens_in: Int!
    current_month_tokens_out: Int!
    current_month_total_cost_usd: Float!
    current_month_interactions: Int!
    budget_tokens: Int          # from agent config
    budget_cost_usd: Float      # from agent config
    usage_percentage: Float     # (actual/budget) * 100
}
```

Add `monthly_token_budget` and `monthly_cost_budget` fields to `agents.config` JSON so each agent can have limits. The agent (or its system prompt) can query this endpoint to know its remaining budget.

#### 7. Optional: Wallet integration for billing

For apps that charge per-agent usage, integrate with the existing wallet system:
- After each execution, deduct `cost_usd` from the company/user wallet
- Use the existing `HasWalletHolderTrait` to resolve wallet holder
- Transaction meta: `{ "agent_id": X, "tokens": Y, "model": "gpt-4" }`

---

### Implementation Order

1. **Migration**: Add pricing to `agent_models`, add token columns to `agent_performance_metrics`
2. **Prism capture**: Update `TrackAgentUsageAction` + `AgentChatMutation` to extract and store token counts
3. **Cost calculation**: Compute cost from `AgentModel` pricing × tokens in `TrackAgentUsageAction`
4. **Aggregation command**: Daily rollup into `agent_usage_snapshots`
5. **GraphQL endpoints**: Admin reporting queries + agent self-awareness query
6. **Budget config**: Add budget fields to agent config, usage percentage calculation
7. **Wallet integration**: Optional, for apps that need billing
