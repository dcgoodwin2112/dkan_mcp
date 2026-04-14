# dkan_mcp — Agent Development Guide

Module-level guidance for AI agents using and developing dkan_mcp. For tool reference tables and installation, see [README.md](README.md).

## Tool Workflows

### Site Orientation

`get_site_status` — single call returning dataset/distribution counts, import health, harvest plan count, and DKAN/Drupal versions. Use as the first call when working with a new DKAN site.

### Data Discovery → Query Validation

1. `list_datasets` — find datasets by title/description
2. `list_distributions(dataset_id)` — get distributions with `resource_id` field
3. `get_datastore_schema(resource_id)` — discover column names and types
4. `get_datastore_stats(resource_id)` — per-column null count, distinct count, min, max, total rows (data quality overview)
5. `query_datastore(resource_id, columns, conditions)` — query with filters
6. `query_datastore(resource_id, expressions, groupings)` — aggregate with GROUP BY (sum, count, avg, max, min)
7. `query_datastore_join(resource_id, join_resource_id, join_on, columns)` — join two resources on a shared column
8. `search_columns(search_term)` — find which resources have columns matching a name or description

Use `search_datasets(keyword)` as an alternative to step 1 when you know a keyword.

### Service Discovery → Dependency Injection

1. `list_services(module: "datastore")` — find service IDs in a module (use `brief: true` for ID-only list)
2. `get_service_info(service_id)` — get constructor params, method signatures, and YAML definition (including `calls` for setter injection and `tags`). Use `methods: "get*"` to filter methods, `include_yaml: false` to skip YAML.
3. `get_class_info(class_name)` — follow return types to discover the full API of returned objects. Use `methods: "query*"` to filter.
4. `discover_api(service_id, method: "getStorage")` — combines steps 2-3 in one call: gets service info and follows return types automatically. Use `depth: 2` to follow two levels.
5. Write `*.services.yml` arguments and constructor type hints from the response

The method signatures include parameter names, types, optionality, and return types — enough to write working service calls without reading source code. The `yaml_definition` field reveals setter injection (`calls`) and service tags not visible from constructor reflection alone.

### Event-Driven Extension

1. `list_events(module: "metastore")` — find event constants and string values (use `brief: true` for name-only list)
2. `get_event_info(event_name)` — see declaring class, existing subscribers, event class, event class methods, and **dispatch_payload**. Use `fields: "constant,dispatch_payload"` to return only specific fields.
3. Write an EventSubscriber with appropriate priority (check existing subscriber priorities to avoid conflicts)

`get_event_info` includes `event_class`, `event_methods` (from subscriber type hints), and `dispatch_payload` (the actual type passed to `getData()` at the dispatch site). For events using `Drupal\common\Events\Event`, the `dispatch_payload.type` field reveals what `getData()` returns (e.g., `MetastoreItemInterface`), with `dispatch_payload.methods` listing its API.

### Permission-Aware Development

1. `list_permissions(module: "datastore")` — see existing permissions and patterns
2. `get_permission_info(permission)` — which routes use it, which roles have it
3. `check_permissions` — validate no orphaned or misconfigured permissions
4. After adding routes, run `check_permissions` again to catch issues early

### Dataset Lifecycle (Create → Publish → Update → Delete)

1. `create_test_dataset(title, download_url)` — create a dataset with a CSV distribution, returns dataset UUID
2. `list_distributions(dataset_id)` — get the `resource_id` for the new distribution
3. `import_resource(resource_id)` — trigger datastore import (synchronous for small CSVs)
4. `get_import_status(resource_id)` — verify import completed
5. `query_datastore(resource_id)` — query the imported data
6. `patch_dataset(identifier, '{"title": "New Title"}')` — partial metadata update (JSON Merge Patch)
7. `update_dataset(identifier, full_metadata_json)` — full metadata replacement (PUT)
8. `unpublish_dataset(identifier)` — archive a dataset (hides from public queries, does not delete)
9. `publish_dataset(identifier)` — re-publish an archived dataset
10. `drop_datastore(resource_id)` — drop the datastore table (use `import_resource` to re-import)
11. `delete_dataset(identifier)` — remove dataset and cascade-delete distributions/datastore tables

### Harvest Management

1. `list_harvest_plans` — see existing harvest plan IDs
2. `get_harvest_plan(plan_id)` — inspect plan config (source URI, extract/load types)
3. `register_harvest(plan_json)` — register a new plan (must include `identifier`, `extract`, `load`)
4. `run_harvest(plan_id)` — execute the harvest (extract → transform → load)
5. `get_harvest_runs(plan_id)` — list all runs with timestamps
6. `get_harvest_run_result(plan_id)` — inspect latest run result (per-dataset status)
7. `deregister_harvest(plan_id)` — remove a plan (does not delete previously harvested datasets)

### Schema Discovery

1. `list_schemas` — discover available schema IDs (dataset, distribution, keyword, theme, etc.)
2. `get_schema(schema_id)` — get the full JSON Schema definition with property types and validation constraints

### Debugging

1. `get_log_types` — see what types of log entries exist
2. `get_recent_logs(type, severity)` — filter to relevant entries (e.g., type: "dkan", severity: 3 for errors)
3. `get_queue_status` — check all DKAN queue depths (import, localization, cleanup)
4. `get_queue_status(queue_name: "datastore_import")` — check a specific queue after deferred imports

### Cache and Module Management

- `clear_cache` — flush all caches after code/config changes (does not rebuild the container)
- `enable_module(module_name)` / `disable_module(module_name)` — install/uninstall modules
- After any write tool that warns about restarting, restart the MCP server for container changes to take effect

### Drupal Introspection

Discover Drupal runtime state without guessing entity types, field names, modules, or routes.

**Entity discovery**: `list_entity_types(group: "content")` → `get_entity_fields(entity_type_id, bundle)` → write entity queries with correct field names

**Module discovery**: `list_modules(name_contains: "dkan")` → check if a module is enabled before using its APIs

**Plugin discovery**: `list_plugins(type: "block")` → find existing plugins before creating new ones

**Config discovery**: `get_config(prefix: "system.")` → `get_config(name: "system.site")` → understand site state

**Route discovery**: `get_route_info(path: "/api/1/")` → understand existing pages and access requirements

### Data Structure Discovery

When you need the array structure returned by a DKAN method (e.g., `gather()` returns `array`):

1. `get_dataset_info(uuid)` — returns the **actual** `DatasetInfo::gather()` output with all plugin-contributed keys. Inspect the response to see exact keys like `importer_status`, `table_name`, `fetcher_status`.
2. `resolve_resource(resource_id)` — returns actual resource data including `import_status`, `datastore_table`, `dataset_uuid` (reverse lookup to owning dataset)
3. `query_datastore(resource_id, limit: 1)` — returns actual data to verify column names/types

**Do NOT rely solely on `get_class_info` for methods returning `array` or `mixed`.** The return type won't tell you the array keys. Instead, call the MCP tool that invokes the method and inspect the real output.

## Resource ID Bridging

Metastore tools use **UUIDs**. Datastore tools use **resource IDs** (`{identifier}__{version}`). `list_distributions` is the bridge — it returns both formats.

| Tool | Accepts |
|---|---|
| `get_dataset`, `list_distributions`, `get_distribution`, `get_dataset_info` | UUID |
| `publish_dataset`, `unpublish_dataset` | UUID |
| `query_datastore`, `get_datastore_schema`, `get_datastore_stats`, `get_import_status`, `import_resource` | `identifier__version` |
| `drop_datastore` | `identifier__version` |
| `resolve_resource` | Either format (but see Common Mistakes) |
| `search_datasets` | keyword string |
| `create_test_dataset` | `title` + `download_url` |
| `update_dataset`, `patch_dataset`, `delete_dataset` | UUID |
| `get_schema` | schema ID string (e.g., `dataset`, `distribution`) |
| `register_harvest` | plan JSON string |
| `run_harvest`, `deregister_harvest` | plan ID string |
| `get_queue_status` | queue name string (optional) |
| `clear_cache`, `enable_module`, `disable_module` | module name or no args |

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
- `sort_direction` — `"asc"` or `"desc"`
- `limit` — 1–500, default 100
- `offset` — default 0

### `search_columns` parameters

- `search_term` — Case-insensitive substring match against column names/descriptions.
- `search_in` — `"name"` (default), `"description"`, or `"both"`.
- `limit` — Max matches to return, default 100.

### `query_datastore_join` parameters

- `join_on` — Simple: `"state=state_abbreviation"` (primary=joined). JSON: `{"left":"t.col","right":"j.col","operator":"="}`
- `columns` — Alias-qualified: `"t.state,j.rate"`. Unqualified defaults to primary (`t`).
- `conditions` — Same as `query_datastore` but supports `"resource":"j"` for joined table filtering. Supports `conditionGroup` for OR logic.
- `expressions` — Same format as `query_datastore`. Aggregate and arithmetic operators supported.
- `groupings` — Comma-separated with alias prefix: `"t.state,j.year"`. Required when using aggregate expressions.

### `get_recent_logs` parameters

- `type` — Log type string (e.g., "dkan", "php", "user", "cron").
- `severity` — Max severity level 0-7. 0=Emergency, 3=Error, 4=Warning, 7=Debug.
- `limit` — Max entries, default 25, max 100.
- `offset` — Pagination offset, default 0.

### Introspection `module` filter

Accepts DKAN module names: `metastore`, `datastore`, `harvest`, `common`, `metastore_search`. Used by `list_services`, `list_events`, `list_permissions`. Omit for all DKAN modules.

## Common Mistakes

| Mistake | Correct Approach |
|---|---|
| Passing distribution UUID to `query_datastore` | Use `resource_id` from `list_distributions` (`identifier__version` format) |
| Passing distribution UUID to `resolve_resource` | Works correctly; `list_distributions` is still the preferred bridge for getting `resource_id` from a dataset |
| Relying on `get_class_info` for array return structures | Use `get_dataset_info` to see actual `gather()` output with all keys including plugin-contributed ones (`importer_status`, `table_name`, etc.) |
| Missing resource→dataset reverse lookup | `resolve_resource` returns `dataset_uuid` — use this instead of iterating all datasets |
| Missing error handling in event subscribers | Always wrap scorer/service calls in try/catch to prevent breaking the event flow |
| Guessing return type APIs from `get_service_info` | Use `get_class_info` to follow return types — e.g., `getStorage()` returns `DatabaseTable`, call `get_class_info("Drupal\\datastore\\Storage\\DatabaseTable")` to see its methods |
| Missing setter injection from `get_service_info` | Check the `yaml_definition.calls` field for setter injection methods not visible in the constructor |
| Using `get_service_info` output without checking `accessCheck()` | If a method signature shows entity queries, the code may need `->accessCheck(TRUE/FALSE)` — `get_service_info` doesn't surface this |
| Calling get_datastore_schema on every resource to find columns | Use `search_columns` to search all resources in one call |
| Querying a resource before import completes | Call `get_import_status` first; status must be `done` |
| Guessing entity types, field names, or bundles | Use `list_entity_types` and `get_entity_fields` to discover them |
| Guessing plugin IDs or route names | Use `list_plugins` or `get_route_info` to discover them |
| Asking the user to check Drupal logs manually | Use `get_recent_logs` to read watchdog entries directly |
| Expecting `get_dataset` to return unpublished datasets | `get_dataset` defaults to published only; use `publish_dataset` to restore visibility |
| Passing plan JSON as an object to `register_harvest` | Parameter is a JSON **string**, not an object — serialize with `json_encode` or pass raw JSON |
| Guessing schema property names for dataset validation | Use `get_schema("dataset")` to get the full JSON Schema with required fields and types |

## When to Use MCP vs Code Reading

| Need | Use MCP | Use Code Reading |
|---|---|---|
| **DKAN** | | |
| Live data, actual schemas, row counts | `query_datastore`, `get_datastore_schema` | — |
| Current permissions and role assignments | `list_permissions`, `get_permission_info` | — |
| Service constructor params, method signatures | `get_service_info` | — |
| Full public API of any class/interface | `get_class_info` | — |
| Which events exist and who subscribes | `list_events`, `get_event_info` | — |
| Event class and method signatures | `get_event_info` (includes `event_class` + `event_methods`) | — |
| Event dispatch payload type (`getData()` returns) | `get_event_info` (includes `dispatch_payload`) | — |
| Data structure of methods returning `array` | `get_dataset_info`, `resolve_resource`, `query_datastore` | — |
| Service YAML definition (setter injection, tags) | `get_service_info` (includes `yaml_definition`) | — |
| Resource→dataset reverse lookup | `resolve_resource` (includes `dataset_uuid`) | — |
| Find datasets with specific column types | `search_columns` | — |
| Cross-dataset correlation | `query_datastore_join` | — |
| Import/harvest state | `get_import_status`, `get_harvest_runs` | — |
| Metadata schema definitions and validation rules | `get_schema` | — |
| Dataset publish/unpublish state management | `publish_dataset`, `unpublish_dataset` | — |
| Harvest plan registration and execution | `register_harvest`, `run_harvest`, `deregister_harvest` | — |
| Datastore table cleanup | `drop_datastore` | — |
| Runtime errors, import failures, permission denials | `get_recent_logs` | — |
| **Drupal** | | |
| Entity types, bundles, field definitions | `list_entity_types`, `get_entity_fields` | — |
| Enabled modules and metadata | `list_modules` | — |
| Site-wide health overview | `get_site_status` | — |
| Site configuration values | `get_config` | — |
| Plugin definitions by type | `list_plugins` | — |
| Route paths, controllers, access | `get_route_info` | — |
| **Always use code reading** | | |
| Method behavior and internal logic | — | Read source code |
| API request/response contracts | — | Read `docs/dkan-api.md` |
| Workflow sequences (what happens on CRUD) | — | Read `docs/dkan-workflows.md` |
| Test patterns, mock-chain usage | — | Read `docs/dkan-testing.md` |

For full per-tool parameter schemas and response shapes, see [docs/tools.md](docs/tools.md).

## Module Development

### Tests

```bash
cd dkan_mcp && vendor/bin/phpunit
cd dkan_mcp && vendor/bin/phpunit tests/src/Unit/Tools/DatastoreToolsTest.php
```

Tests use standalone stubs in `tests/stubs/` (no Drupal bootstrap). Stub classes replicate DKAN service interfaces with minimal implementations.

### Adding a Tool

1. Add a public method to the appropriate tool class in `src/Tools/` (or create a new tool class if it doesn't fit existing categories)
2. Register it in `McpServerFactory` in the corresponding `register*Tools()` method using `$builder->addTool()` — define `handler`, `name`, `description`, `annotations` (`$readOnly` for read tools, `new ToolAnnotations(readOnlyHint: FALSE)` for write tools), and `inputSchema`
3. Add a unit test in `tests/src/Unit/Tools/`
4. If the tool should be available over HTTP, ensure it's in a tool group listed in `McpController::HTTP_TOOL_GROUPS`

If adding a new tool class: create it as a Drupal service in `dkan_mcp.services.yml`, inject it into `McpServerFactory`, add a `register*Tools()` method, and add the group key to `McpServerFactory::TOOL_GROUPS`.

### opis/json-schema Conflict

The MCP SDK requires `opis/json-schema ^2` but DKAN requires `^1`. The SDK lives in `dkan_mcp/vendor/` with opis packages removed post-install to prevent autoloader collisions. See README.md Architecture section for details. Run `composer run-script post-install-cleanup` after any `composer install/update` in the module directory.

## Slash Commands

Four Claude Code commands ship in `claude-commands/`: `/scaffold-drupal-service`, `/add-event-subscriber`, `/add-drupal-route`, `/validate-module`. These use MCP tools internally for service discovery, event introspection, and permission validation. See README.md for installation (symlink from project root `.claude/commands/` to `web/modules/custom/dkan_mcp/claude-commands/`).
