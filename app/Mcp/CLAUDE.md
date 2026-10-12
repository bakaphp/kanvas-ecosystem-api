# MCP servers (`laravel/mcp`)

Kanvas exposed **as** an MCP server. Not to be confused with `Neuron/Tools/Mcp` and
`NervousSystem` MCP connections, which are Kanvas agents **consuming** third-party MCP servers.

## Where things live

- `routes/ai.php` — one `Mcp::web()` per server, under the API prefix (`/v1/mcp/...`).
- `app/Mcp/Servers/` — a server is a name, instructions and a `$tools` list.
- `app/Mcp/Tools/{Area}/` — one class per tool, all extending `KanvasTool`.

## Auth is the three Kanvas headers, all mandatory

`X-Kanvas-App`, `X-Kanvas-Key` and `X-Kanvas-Location` are resolved by the global
`KanvasAppKeyMiddleware`, which treats each as optional. `EnsureKanvasMcpContext` (on the route group)
is what makes them required and checks the branch's company belongs to the app. The acting user is the
app key's owner; the company comes from the branch. Never register an MCP route outside that group.

## Writing a tool

- Extend `KanvasTool` and implement `execute(Request, ActingContext): array`. The base turns the array
  into structured content and turns not-found / `ValidationException` / bad enum values into tool
  errors — do not catch those yourself.
- Resolve every record through `findProject` / `findTask` / `findSubtask` (or
  `getByIdFromCompanyApp`). Ids are model-supplied; an unscoped `find()` is a cross-tenant read.
- Call the existing domain Action. A tool is an adapter like a GraphQL resolver, not a place for logic.
- For partial updates pass `$this->sent($request, [...])` to the DTO's `forUpdate`, so an omitted
  field keeps its value.
- Set `$name` explicitly (snake_case) and add the tool to the server's `$tools`.

## Board vocabulary is not the domain's

| MCP tool says | Domain model |
|---|---|
| task (a card on the board) | `NervousSystem\Plan\Models\Plan` |
| subtask | `NervousSystem\Plan\Models\Task` |
| column | an entry in `Project::config['board_columns']`, addressed by `key` |

A plan only stores `board_column_key` after a board drag. Read its column with
`PresentsProjectBoard::columnKeyOf()`, never the raw attribute, or tasks created elsewhere vanish from
their column.

## Tests

`tests/Intelligence/NervousSystem/ProjectMcpServerTest.php` — tools are called through
`ProjectServer::tool(...)`, the headers through a real `postJson` to the route.
