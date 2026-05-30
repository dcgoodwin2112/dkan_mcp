# dkan_mcp — Agent Development Guide

Module-level guidance for AI agents using and developing dkan_mcp. For tool reference tables and installation, see [README.md](README.md). For full per-tool parameter schemas and response shapes, see [docs/tools.md](docs/tools.md).

This module covers **DKAN data introspection and administration**. Generic Drupal introspection/admin (services, events, permissions, entity/plugin/config/route discovery, watchdog logs, cache clear, module enable/disable) is intentionally out of scope — use `drush` directly for those.

**Conventions:** Tool input parameters are camelCase (`resourceId`, `sortField`, `datasetId`); response payloads keep DKAN's snake_case keys (`resource_id`, `dataset_uuid`). Most tools map directly to DKAN REST endpoints; `get_dataset_info`, `resolve_resource`, `search_columns`, `get_datastore_stats`, and the dictionary enrichment in `get_datastore_schema` are agent-convenience value-adds with no single REST equivalent.

## Tool Workflows

### Site Orientation

`get_site_status` — single call returning dataset/distribution counts, import health, harvest plan count, and DKAN/Drupal versions. Use as the first call when working with a new DKAN site.

### Data Discovery → Query Validation

1. `list_datasets` — find datasets by title/description
2. `list_distributions(datasetId)` — get distributions with `resource_id` field
3. `get_datastore_schema(resourceId)` — discover column names and types
4. `get_datastore_stats(resourceId)` — per-column null count, distinct count, min, max, total rows (data quality overview)
5. `query_datastore(resourceId, columns, conditions)` — query with filters
6. `query_datastore(resourceId, expressions, groupings)` — aggregate with GROUP BY (sum, count, avg, max, min)
7. `query_datastore_join(resourceId, joinResourceId, joinOn, columns)` — join two resources on a shared column
8. `search_columns(searchTerm)` — find which resources have columns matching a name or description

Use `search_datasets(keyword)` as an alternative to step 1 when you know a keyword.

### Dataset Lifecycle (Create → Import → Query → Update → Delete)

1. `update_dataset(identifier, full_metadata_json)` — create or replace a dataset (PUT semantics; upserts when the UUID doesn't exist). Include a `distribution` array with `downloadURL` to attach data files. Use `post_metastore_item('dataset', json)` as an alternative for explicit creation.
2. `list_distributions(datasetId)` — get the `resource_id` for the new distribution
3. `import_resource(resourceId)` — trigger datastore import (synchronous for small CSVs)
4. `get_import_status(resourceId)` — verify import completed (status must be `done`)
5. `query_datastore(resourceId)` — query the imported data
6. `patch_dataset(identifier, '{"title": "New Title"}')` — partial metadata update (JSON Merge Patch)
7. `unpublish_dataset(identifier)` — archive a dataset (hides from public queries, does not delete)
8. `publish_dataset(identifier)` — re-publish an archived dataset
9. `drop_datastore(resourceId)` — drop the datastore table (use `import_resource` to re-import)
10. `delete_dataset(identifier)` — remove dataset and cascade-delete distributions/datastore tables

### Metastore Item Management

For non-dataset schemas (data-dictionary, distribution, theme, keyword, etc.):

1. `list_schemas` → `get_schema(schemaId)` — discover required fields
2. `post_metastore_item(schemaId, metadata)` — create an item (returns the new identifier)
3. `patch_metastore_item(schemaId, identifier, metadata)` — partial update via JSON Merge Patch

### Harvest Management

1. `list_harvest_plans` — see existing harvest plan IDs
2. `get_harvest_plan(planId)` — inspect plan config (source URI, extract/load types)
3. `register_harvest(plan_json)` — register a new plan (must include `identifier`, `extract`, `load`)
4. `run_harvest(planId)` — execute the harvest (extract → transform → load)
5. `get_harvest_runs(planId)` — list all runs with timestamps
6. `get_harvest_run_result(planId)` — inspect latest run result (per-dataset status)
7. `deregister_harvest(planId)` — remove a plan (does not delete previously harvested datasets)

### Schema Discovery

1. `list_schemas` — discover available schema IDs (dataset, distribution, keyword, theme, etc.)
2. `get_schema(schemaId)` — get the full JSON Schema definition with property types and validation constraints

### Data Structure Discovery

When you need the array structure returned by a DKAN method (e.g., `gather()` returns `array`):

1. `get_dataset_info(uuid)` — returns the **actual** `DatasetInfo::gather()` output with all plugin-contributed keys. Inspect the response to see exact keys like `importer_status`, `table_name`, `fetcher_status`.
2. `resolve_resource(id)` — pass a distribution UUID or resource ID; returns actual resource data including `import_status`, `datastore_table`, `dataset_uuid` (reverse lookup to owning dataset)
3. `query_datastore(resourceId, limit: 1)` — returns actual data to verify column names/types

### Operational Checks

- `get_queue_status` — check all DKAN queue depths (import, localization, cleanup)
- `get_queue_status(queueName: "datastore_import")` — check a specific queue after deferred imports

For watchdog logs, container rebuilds, or module management, use `drush` directly (`drush watchdog:show`, `drush cr`, `drush en`).

## Resource ID Bridging

Metastore tools use **UUIDs**. Datastore tools use **resource IDs** (`{identifier}__{version}`). `list_distributions` is the bridge — it returns both formats.

| Tool | Accepts |
|---|---|
| `get_dataset`, `list_distributions`, `get_distribution`, `get_dataset_info` | UUID |
| `publish_dataset`, `unpublish_dataset`, `update_dataset`, `patch_dataset`, `delete_dataset` | UUID |
| `query_datastore`, `get_datastore_schema`, `get_datastore_stats`, `get_import_status`, `import_resource`, `drop_datastore` | `identifier__version` |
| `resolve_resource` | Either format (but see Common Mistakes) |
| `search_datasets` | keyword string |
| `get_schema` | schema ID string (e.g., `dataset`, `distribution`) |
| `post_metastore_item` | schema ID + metadata JSON string |
| `patch_metastore_item` | schema ID + identifier + metadata JSON string |
| `register_harvest` | plan JSON string |
| `run_harvest`, `deregister_harvest` | plan ID string |
| `get_queue_status` | queue name string (optional) |

To go from a dataset UUID to queryable data: `list_distributions` → use `resource_id` field → pass to datastore tools.

## Parameter Reference

### `query_datastore` conditions

JSON string containing an array of condition objects:

```json
[{"property": "state", "value": "CA", "operator": "="}]
```

**Operators**: `=`, `<>`, `<`, `<=`, `>`, `>=`, `like`, `contains`, `starts with`, `in`, `not in`, `between`

For `in`/`not in`, value is an array: `{"property": "state", "value": ["CA","TX"], "operator": "in"}`

For `between`, value is a two-element array: `{"property": "age", "value": [18, 65], "operator": "between"}`

**Compound conditions (OR logic)**: Use `conditionGroup` objects with `groupOperator: "or"`:
```json
[{"groupOperator": "or", "conditions": [
  {"property": "state", "value": "CA", "operator": "="},
  {"property": "state", "value": "TX", "operator": "="}
]}]
```
Groups can be nested recursively for complex boolean logic.

### `query_datastore` expressions

- `expressions` — JSON array string: `'[{"operator":"sum","operands":["amount"],"alias":"total"}]'`
  - **Aggregate operators**: `sum`, `count`, `avg`, `max`, `min` (1 operand, use with `groupings`)
  - **Arithmetic operators**: `+`, `-`, `*`, `/`, `%` (2 operands, row-level computed columns)
  - Cannot mix aggregate and arithmetic operators in the same query
- `groupings` — comma-separated string: `"state,year"` (columns to GROUP BY)
- All non-aggregated columns must appear in `groupings`
- Can combine with `columns` (plain columns + expressions in properties)
- Arithmetic example: `'[{"operator":"+","operands":["col1","col2"],"alias":"total"}]'`
- Operands can be nested expressions: `["col1", {"operator":"*","operands":["col2","col3"]}]`

### `query_datastore` other parameters

- `columns` — comma-separated string: `"name,age,state"` (omit for all columns)
- `sortDirection` — `"asc"` or `"desc"`
- `limit` — 1–500, default 100
- `offset` — default 0

### `search_columns` parameters

- `searchTerm` — Case-insensitive substring match against column names/descriptions.
- `searchIn` — `"name"` (default), `"description"`, or `"both"`.
- `limit` — Max matches to return, default 100.

### `query_datastore_join` parameters

- `joinOn` — Simple: `"state=state_abbreviation"` (primary=joined). JSON: `{"left":"t.col","right":"j.col","operator":"="}`
- `columns` — Alias-qualified: `"t.state,j.rate"`. Unqualified defaults to primary (`t`).
- `conditions` — Same as `query_datastore` but supports `"resource":"j"` for joined table filtering. Supports `conditionGroup` for OR logic.
- `expressions` — Same format as `query_datastore`. Aggregate and arithmetic operators supported.
- `groupings` — Comma-separated with alias prefix: `"t.state,j.year"`. Required when using aggregate expressions.

## Common Mistakes

| Mistake | Correct Approach |
|---|---|
| Passing distribution UUID to `query_datastore` | Use `resource_id` from `list_distributions` (`identifier__version` format) |
| Passing distribution UUID to `resolve_resource` | Works correctly; `list_distributions` is still the preferred bridge for getting `resource_id` from a dataset |
| Relying on a method's `array` return type for structure | Use `get_dataset_info` to see actual `gather()` output with all keys including plugin-contributed ones (`importer_status`, `table_name`, etc.) |
| Missing resource→dataset reverse lookup | `resolve_resource` returns `dataset_uuid` — use this instead of iterating all datasets |
| Calling `get_datastore_schema` on every resource to find columns | Use `search_columns` to search all resources in one call |
| Querying a resource before import completes | Call `get_import_status` first; status must be `done` |
| Expecting `get_dataset` to return unpublished datasets | `get_dataset` defaults to published only; use `publish_dataset` to restore visibility |
| Passing plan JSON as an object to `register_harvest` | Parameter is a JSON **string**, not an object — serialize with `json_encode` or pass raw JSON |
| Passing dataset metadata as an object to `update_dataset`/`post_metastore_item` | Parameter is a JSON **string**, not an object |
| Guessing schema property names for dataset validation | Use `get_schema("dataset")` to get the full JSON Schema with required fields and types |

## When to Use MCP vs Code Reading

| Need | Use MCP | Use Code Reading |
|---|---|---|
| Live data, actual schemas, row counts | `query_datastore`, `get_datastore_schema` | — |
| Data structure of methods returning `array` | `get_dataset_info`, `resolve_resource`, `query_datastore` | — |
| Resource→dataset reverse lookup | `resolve_resource` (includes `dataset_uuid`) | — |
| Find datasets with specific column types | `search_columns` | — |
| Cross-dataset correlation | `query_datastore_join` | — |
| Import/harvest state | `get_import_status`, `get_harvest_runs` | — |
| Metadata schema definitions and validation rules | `get_schema` | — |
| Dataset publish/unpublish state management | `publish_dataset`, `unpublish_dataset` | — |
| Harvest plan registration and execution | `register_harvest`, `run_harvest`, `deregister_harvest` | — |
| Datastore table cleanup | `drop_datastore` | — |
| Site-wide health overview | `get_site_status` | — |
| DKAN queue depths | `get_queue_status` | — |
| **Use drush, not MCP** | | |
| Watchdog logs, cache clear, module enable/disable | — | `drush watchdog:show`, `drush cr`, `drush en` |
| Service/event/permission/entity/plugin/route introspection | — | `drush`, IDE, source |
| **Always use code reading** | | |
| Method behavior and internal logic | — | Read source code |
| API request/response contracts | — | Read `dkan-ai-skills/claude-skills/dkan-module-author/reference/dkan-api.md` |
| Workflow sequences (what happens on CRUD) | — | Read `dkan-ai-skills/claude-skills/dkan-module-author/reference/dkan-workflows.md` |
| Test patterns, mock-chain usage | — | Read `dkan-ai-skills/claude-skills/dkan-module-author/reference/dkan-testing.md` |

## Module Development

### Tests

```bash
cd docroot/modules/custom/dkan_mcp && ../../../../vendor/bin/phpunit
cd docroot/modules/custom/dkan_mcp && ../../../../vendor/bin/phpunit tests/src/Unit/Tools/HarvestToolsTest.php
```

Tests use standalone stubs in `tests/stubs/` (no Drupal bootstrap). Stub classes replicate DKAN service interfaces with minimal implementations. Tests for the shared `MetastoreTools`, `DatastoreTools`, and `SearchTools` classes live in the `dkan_query_tools` module.

### Adding a Tool

**For catalog/metastore/datastore/search operations**, edit the relevant class in the **`dkan_query_tools`** module (`MetastoreTools`, `DatastoreTools`, `SearchTools`) — the shared library consumed by dkan_mcp and dkan_drupal_ai_query. Add the unit test in `dkan_query_tools/tests/src/Unit/Tool/`.

**For MCP-server-specific operations** (harvest, resource, write, status):

1. Add a public method to the appropriate tool class in `dkan_mcp/src/Tools/` (or create a new tool class if it doesn't fit).
2. Add a unit test in `dkan_mcp/tests/src/Unit/Tools/`.

**Then for either case**, register the new method as an MCP tool:

3. Add a spec entry to the relevant group in the `McpServerFactory::TOOL_GROUPS` constant: `name`, `class` (the tool-class FQCN), `method`, `readOnly` (FALSE for write tools), and `description`. The input schema is auto-generated from the method's reflection and `@param` docblocks — add an explicit `input` array only for complex schemas (see `SCHEMA_QUERY_DATASTORE`), and an `output` array for high-value returns.
4. If the tool should be available over HTTP, ensure its group is in `McpController::HTTP_TOOL_GROUPS`.

If adding a new dkan_mcp-specific tool class: register it as a Drupal service in `dkan_mcp.services.yml`, inject it into `McpServerFactory`'s constructor, add it to the `ToolServiceContainer` map in `McpServerFactory::toolContainer()` (so the SDK resolves its `[Class::class, 'method']` handlers), and add a new group key to `McpServerFactory::TOOL_GROUPS`.
