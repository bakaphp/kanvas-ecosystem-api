# Templates — Kanvas Ecosystem API

Loads when work touches `src/Kanvas/Templates/`. Covers how `email_templates` rows render and who may
write them.

## Two engines, chosen by the row

`email_templates.engine` is `twig` or `blade` (`TemplateEngineEnum`).

| | `twig` | `blade` |
|---|---|---|
| Who writes it | anyone allowed to manage templates: GraphQL, agent tools, app setup, seeders | **only by hand, in the database** |
| Rendered by | `TemplateSandbox::renderHtml()` — Twig sandbox, plain-array context | `Blade::render()` with live models |
| Through Eloquent | created, edited, deleted | refused: `Templates::booted()` throws on save and delete |

- New rows are always `twig`. Nothing in code may create a `blade` row or change one; the model guard
  throws, and `CreateTemplateAction` hands an existing `blade` row with the same key back unchanged so
  setup and sync can rerun over an app. A resolver acting for a user calls `assertNotBlade()` on that
  result.
- To make a hand-made `blade` template editable in the UI, migrate it to Twig and flip `engine` in the
  database. There is no code path that does the reverse.
- `RenderTemplateAction` is the only place allowed to call Blade (`BladeRenderingIsConfinedTest`). A
  parent and child that are both `blade` compile as one text; any other pair renders separately and the
  child's output fills the parent's `[body]` slot after the parent has rendered.
- A raw `$templateContent` string passed to `RenderTemplateAction::execute()` is not a row, so it always
  goes through the sandbox.

## Writing a twig template

Repo templates live in `resources/views/{emails,push}/*.twig`; `SyncEmailTemplateAction`,
`TemplateSeeder` and `CustomerUpdateEmailTemplateSeeder` store them. `RepoTemplatesRenderTest` renders
each one from the data its notification passes.

- The sandbox allows `if`, `for`, `set`, a short filter list (`TemplateSandbox::FILTERS`, plus `raw`
  and `markdown` in HTML mode), no functions, no method calls, no object properties. A template is
  compiled and security-checked when it is saved, so a refused one never reaches a send.
- The range operator (`1..n`) is refused: it compiles to PHP's `range()`, outside the sandbox.
- The context is flattened to arrays. A model gives its attributes plus the relations already loaded,
  so a notification loads what its template reads (`NewLeadNotification`). Anything computed by a
  method has to arrive as a field.
- A model whose row holds something a template must never print implements `ExposesTemplateData` and
  lists its fields (`Users`, `Apps`, `Companies`, `Order`). An HTML template can send anything it prints
  to any host through an image URL, so a new model with secrets in its row needs the same.
- Dates: use `|date(format, false)` to keep the value's own timezone, the way `Carbon::format()` did.
