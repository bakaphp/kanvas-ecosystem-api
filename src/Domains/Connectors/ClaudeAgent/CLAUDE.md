# Claude Agent connector — Kanvas Ecosystem API

## Which coding backend: pi.dev or Claude?

Kanvas has two async coding backends and they are NOT interchangeable. Without a stated rule an
agent picks by tool-name similarity, which is the same failure mode as `create_google_sheet_tab`
one layer up. The rule lives in both dispatch tools' descriptions (that is what the model actually
reads at decision time); this table is the engineer-facing version.

| The work... | Backend | Why |
|---|---|---|
| is a narrow change inside ONE repository | **pi.dev** (`dispatch_coding_task`) | cheaper, endpoint is ours (`pidev_base_url` is app-scoped), and `check_coding_setup` gives a preflight |
| needs to read live Kanvas data WHILE it runs | **Claude** (`dispatch_long_task`) | only the Claude path has `CustomToolBridgeService` — the sandbox parks, the call comes back over the event stream, and PHP runs it under tenant scope. Credentials never enter the sandbox |
| has checkable acceptance criteria | **Claude** | `rubric` grades and iterates. Criteria must be verifiable INSIDE a sandbox with no database — "the CSV has a numeric price column" works, "the tests pass" does not |
| spans more than one repository | **Claude** | `repoSlugs` is a list; pi.dev takes one slug |
| must hand back a produced file | **Claude** | `PullSessionOutputsAction` attaches `/mnt/session/outputs/` to the Plan; pi.dev returns only a PR |

Both create a NervousSystem `Plan` + `Task`, both poll to a terminal status, and both end in a pull
request. The difference is entirely what the agent can reach while it works.

**Both are supported deliberately.** If that ever stops being worth two allow-list services, two poll
jobs and two custom-field sets, consolidate on the Claude path — it is the strict superset — rather
than leaving the choice unstated.
