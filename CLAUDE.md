# dkan_mcp — Agent Development Guide

Module-level guidance for AI agents using and developing dkan_mcp. For tool reference tables and installation, see [README.md](README.md).

## Tool Workflows

### Data Discovery → Query Validation

1. `list_datasets` — find datasets by title/description
2. `list_distributions(dataset_id)` — get distributions with `resource_id` field
3. `get_datastore_schema(resource_id)` — discover column names and types
4. `query_datastore(resource_id, columns, conditions)` — query with filters

Use `search_datasets(keyword)` as an alternative to step 1 when you know a keyword.

### Service Discovery → Dependency Injection

1. `list_services(module: "datastore")` — find service IDs in a module
2. `get_service_info(service_id)` — get constructor params, method signatures with types
3. Write `*.services.yml` arguments and constructor type hints from the response

The method signatures include parameter names, types, optionality, and return types — enough to write working service calls without reading source code.

### Event-Driven Extension

1. `list_events(module: "metastore")` — find event constants and string values
2. `get_event_info(event_name)` — see declaring class and existing subscribers
3. Write an EventSubscriber with appropriate priority (check existing subscriber priorities to avoid conflicts)

**Limitation**: `get_event_info` returns subscriber classes/methods but not event object data structure. Read the event class source to know what data is available in the subscriber callback.

### Permission-Aware Development

1. `list_permissions(module: "datastore")` — see existing permissions and patterns
2. `get_permission_info(permission)` — which routes use it, which roles have it
3. `check_permissions` — validate no orphaned or misconfigured permissions
4. After adding routes, run `check_permissions` again to catch issues early

### Create Test Data → Import → Query

1. `create_test_dataset(title, download_url)` — create a dataset with a CSV distribution, returns dataset UUID
2. `list_distributions(dataset_id)` — get the `resource_id` for the new distribution
3. `import_resource(resource_id)` — trigger datastore import (synchronous for small CSVs)
4. `get_import_status(resource_id)` — verify import completed
5. `query_datastore(resource_id)` — query the imported data

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

## Resource ID Bridging

Metastore tools use **UUIDs**. Datastore tools use **resource IDs** (`{identifier}__{version}`). `list_distributions` is the bridge — it returns both formats.

| Tool | Accepts |
|---|---|
| `get_dataset`, `list_distributions`, `get_distribution`, `get_dataset_info` | UUID |
| `query_datastore`, `get_datastore_schema`, `get_import_status`, `import_resource` | `identifier__version` |
| `resolve_resource` | Either format (but see Common Mistakes) |
| `search_datasets` | keyword string |
| `create_test_dataset` | `title` + `download_url` |
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

### `query_datastore` other parameters

- `columns` — comma-separated string: `"name,age,state"` (omit for all columns)
- `sort_direction` — `"asc"` or `"desc"`
- `limit` — 1–500, default 100
- `offset` — default 0

### Introspection `module` filter

Accepts DKAN module names: `metastore`, `datastore`, `harvest`, `common`, `metastore_search`. Used by `list_services`, `list_events`, `list_permissions`. Omit for all DKAN modules.

## Common Mistakes

| Mistake | Correct Approach |
|---|---|
| Passing distribution UUID to `query_datastore` | Use `resource_id` from `list_distributions` (`identifier__version` format) |
| Passing distribution UUID to `resolve_resource` | Use `resource_id` format instead — distribution UUID resolution fails due to DKAN not including `%Ref:downloadURL` in distribution API responses |
| Assuming `get_event_info` returns event payload shape | Read the event class source for data structure; `get_event_info` only returns subscribers |
| Using `get_service_info` output without checking `accessCheck()` | If a method signature shows entity queries, the code may need `->accessCheck(TRUE/FALSE)` — `get_service_info` doesn't surface this |
| Querying a resource before import completes | Call `get_import_status` first; status must be `done` |
| Guessing entity types, field names, or bundles | Use `list_entity_types` and `get_entity_fields` to discover them |
| Guessing plugin IDs or route names | Use `list_plugins` or `get_route_info` to discover them |

## When to Use MCP vs Code Reading

| Need | Use MCP | Use Code Reading |
|---|---|---|
| **DKAN** | | |
| Live data, actual schemas, row counts | `query_datastore`, `get_datastore_schema` | — |
| Current permissions and role assignments | `list_permissions`, `get_permission_info` | — |
| Service constructor params, method signatures | `get_service_info` | — |
| Which events exist and who subscribes | `list_events`, `get_event_info` | — |
| Import/harvest state | `get_import_status`, `get_harvest_runs` | — |
| **Drupal** | | |
| Entity types, bundles, field definitions | `list_entity_types`, `get_entity_fields` | — |
| Enabled modules and metadata | `list_modules` | — |
| Site configuration values | `get_config` | — |
| Plugin definitions by type | `list_plugins` | — |
| Route paths, controllers, access | `get_route_info` | — |
| **Always use code reading** | | |
| Method behavior and internal logic | — | Read source code |
| Event payload/data structure | — | Read event class |
| API request/response contracts | — | Read `docs/dkan-api.md` |
| Workflow sequences (what happens on CRUD) | — | Read `docs/dkan-workflows.md` |
| Test patterns, mock-chain usage | — | Read `docs/dkan-testing.md` |

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

If adding a new tool class: create it as a Drupal service in `dkan_mcp.services.yml`, inject it into `McpServerFactory`, and add a `register*Tools()` method.

### opis/json-schema Conflict

The MCP SDK requires `opis/json-schema ^2` but DKAN requires `^1`. The SDK lives in `dkan_mcp/vendor/` with opis packages removed post-install to prevent autoloader collisions. See README.md Architecture section for details. Run `composer run-script post-install-cleanup` after any `composer install/update` in the module directory.

## Slash Commands

Four Claude Code commands ship in `claude-commands/`: `/scaffold-drupal-service`, `/add-event-subscriber`, `/add-drupal-route`, `/validate-module`. These use MCP tools internally for service discovery, event introspection, and permission validation. See README.md for installation (symlink into `.claude/commands/`).
