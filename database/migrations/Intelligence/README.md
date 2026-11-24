# Intelligence migrations

## MCP tool categories

Every MCP catalog row in `nervous_system_tools` must have a `tool_category_id`. Categories shared by
all apps live in `nervous_system_tool_categories` with `apps_id = 0`; use their stable `slug` to look
up the id rather than hard-coding an environment-specific id. The category relation is intentionally
nullable for non-MCP tools and remains optional in GraphQL.

When adding an MCP tool migration:

1. Choose the closest existing platform category (for example `crm`, `communication`, `calendar`,
   `knowledge`, `commerce`, `code`, or `workflow`). Add a new global category only when none fits.
2. Resolve its id from the Intelligence connection using both `apps_id = 0` and the category `slug`.
3. Include that id as `tool_category_id` in the `nervous_system_tools` insert.
4. Keep a data backfill's assignment predicate limited to MCP rows so existing non-MCP categories are
   not changed.

```php
$categoryId = DB::connection('intelligence')->table('nervous_system_tool_categories')
    ->where('apps_id', 0)
    ->where('slug', 'crm')
    ->value('id');

DB::connection('intelligence')->table('nervous_system_tools')->insert([
    // Other catalog fields...
    'tool_type' => ToolTypeEnum::MCP->value,
    'tool_category_id' => $categoryId,
]);
```

The category backfill migration assigns uncategorized existing MCP tools to a global category and
uses `other` only when no more specific match applies. It matches a term at the start of a word in
the tool name, never as a bare substring (`sap` must not claim "WhatsApp"). Its `down()` intentionally
leaves category rows and assignments in place so rolling back cannot erase catalog data created or
edited later.
