> **Deprecated**: This document covers the original 34-tool suite and is no longer current. See [tool-suite-review.md](../tool-suite-review.md) for the comprehensive 45-tool assessment.

# Evaluation: dkan_mcp MCP Tools for DKAN Module Development

Analysis of how useful the 34 MCP tools are for AI agents (like Claude Code) developing custom Drupal modules for DKAN. Tools span two platform scopes: DKAN-specific tools (18) require DKAN modules; DKAN-scoped introspection tools (7) use Drupal APIs filtered to DKAN services/events/permissions; Drupal-generic tools (9) work on any Drupal site.

## Strengths

### Runtime Data Access

The primary value. An AI agent building DKAN modules can:

- **Discover live datasets and distributions** (`list_datasets`, `list_distributions`) to understand actual metadata structure rather than guessing from schema definitions
- **Inspect real datastore tables** (`get_datastore_schema`, `query_datastore`) to build queries against actual column names/types instead of mocking everything
- **Validate import state** (`get_import_status`) before writing import-dependent code
- **Resolve resource IDs** (`list_distributions` returns `resource_id` in `{identifier}__{version}` format) — saves reverse-engineering the `%Ref:downloadURL` extraction logic
- **Explore harvest configuration** (`get_harvest_plan`, `get_harvest_runs`) — plan configs and run history are entity data, invisible in source code

### System Introspection

Added in the second iteration, these tools let an agent understand DKAN's runtime architecture without reading source code:

- **Discover services and their APIs** (`list_services`, `get_service_info`) — constructor dependencies, method signatures with parameter types and return types. An agent can wire up dependency injection by querying `get_service_info` on any DKAN service.
- **Find integration points** (`list_events`, `get_event_info`) — event constants, string values, declaring classes, and registered subscribers. Enables building event subscribers without tracing dispatch calls through workflow code.
- **Implement access control** (`list_permissions`, `get_permission_info`, `check_permissions`) — permission definitions, route bindings, role assignments, and misconfiguration detection. `check_permissions` found a real issue: the `datastore_data_preview.preview` route references `administer dkan` but that permission isn't defined by DKAN.
- **Trace resource lifecycle** (`resolve_resource`) — maps a resource_id through all three perspectives (source, local_file, local_url) to the datastore table name. Eliminates guesswork about DKAN's resource management internals.

### Coverage by Category

| Category | Tools | Development Value |
|---|---|---|
| Data discovery & exploration | `list_datasets`, `get_dataset`, `list_distributions`, `get_distribution`, `get_catalog`, `list_schemas`, `get_dataset_info` | Strong — live metadata structure, resource ID resolution |
| Query & validation | `query_datastore`, `get_datastore_schema`, `get_import_status` | Strong — real table schemas, data validation |
| Search | `search_datasets` | Moderate — useful for testing search integration |
| Harvest | `list_harvest_plans`, `get_harvest_plan`, `get_harvest_runs`, `get_harvest_run_result` | Strong — plan configs and run history are runtime-only data |
| DKAN Introspection | `list_services`, `get_service_info`, `list_events`, `get_event_info`, `list_permissions`, `get_permission_info`, `check_permissions`, `resolve_resource` | Strong — service APIs, events, permissions, resource chain tracing |
| Write (DKAN) | `create_test_dataset`, `import_resource` | Strong — test data creation and import triggering |
| Write (Drupal) | `clear_cache`, `enable_module`, `disable_module` | Strong — cache and module management |
| Drupal introspection | `list_entity_types`, `get_entity_fields`, `list_modules`, `get_config`, `list_plugins`, `get_route_info` | Strong — entity types, fields, plugins, config, routes without reading YAML |

## What Code Reading Already Covers

The `docs/` directory + source code provide some **architecture and API knowledge** without MCP, but the introspection tools now offer a faster, more reliable path to the same information:

| Need | Code/Docs Source | MCP Alternative |
|---|---|---|
| Service IDs, method signatures, constructors | `docs/dkan-services.md` | `list_services` + `get_service_info` (runtime-accurate) |
| REST API endpoints, request/response shapes | `docs/dkan-api.md` | — |
| Data flows (CRUD, import, harvest, query) | `docs/dkan-workflows.md` | — |
| Data model, references, perspectives | `docs/dkan-overview.md` | `resolve_resource` (live perspective chain) |
| Test patterns, mock-chain usage | `docs/dkan-testing.md` + existing tests | — |
| Service dependencies, routing, permissions | `*.services.yml`, `*.routing.yml` files | `get_service_info`, `get_permission_info` |
| Event system | Source code grep for `EVENT_` constants | `list_events` + `get_event_info` |

## Remaining Gaps

### Low Priority

**Database internals** — Table naming conventions (`datastore_` + MD5), `record_number` field behavior, data dictionary enforcement — learnable from code but not queryable. `resolve_resource` partially addresses this by returning the computed table name.

**Distribution UUID resolution** — `resolve_resource` with a distribution UUID fails because `MetastoreService::get('distribution', $uuid)` returns distributions without `%Ref:downloadURL` metadata. The resource_id format (`identifier__version`) works correctly. This is a DKAN API limitation, not a tool bug.

**Event payload shapes** — `list_events` and `get_event_info` expose event names and subscribers but not the event object's data structure. An agent still needs to check the event class to know what data is available in a subscriber.

## Introspection Tools: Detailed Assessment

### ServiceTools

`list_services` returned 64 DKAN services across all modules. Module filtering works correctly (19 metastore services when filtered). `get_service_info` on `dkan.metastore.service` returned complete constructor parameters with types (SchemaRetriever, DataFactory, ValidMetadataFactory, LoggerInterface, EventDispatcherInterface) and 19 public methods with full parameter/return type signatures. This is sufficient for an agent to write a service that injects and calls MetastoreService without reading any source code.

### EventTools

`list_events` discovered 13 events across common, datastore, metastore, and metastore_search modules. Module filtering correctly narrowed to 6 metastore events. `get_event_info` on `dkan_metastore_dataset_update` returned the declaring class (LifeCycle), module, and one subscriber (DatastoreSubscriber::purgeResources). This tells an agent exactly where to hook in and what existing behavior to expect.

### PermissionTools

`list_permissions` found 19 DKAN permissions. `get_permission_info` on `datastore_api_import` returned the permission definition, two routes that require it (`/api/1/datastore/imports` GET and POST), and two roles that have it (administrator, api_user). `check_permissions` detected 2 issues: an orphaned route permission (`administer dkan` on the data preview route) and a deprecated permission (`post put delete datasets through the api`).

### ResourceTools

`resolve_resource` with a resource_id (`3a187a87dc6cd47c48b6b4c4785224b7__1773329007`) returned the complete reference chain: all three perspectives (source → remote URL, local_file → `public://resources/...`, local_url → local HTTPS URL), the computed datastore table name (`datastore_577f357a8d4f1c6c67cbd33759fec9e3`), and import status. This is the single most useful tool for understanding how DKAN manages data files.

## Concrete Example: Building datastore_data_preview

| Step | Without MCP | With MCP |
|---|---|---|
| Find resource ID format | Trace `ResourceMapper` → `Referencer` code | `list_distributions` returns it directly |
| Get table columns | Mock `DatabaseTable`, guess schema | `get_datastore_schema` returns real columns |
| Test query logic | Unit tests with mocked storage | `query_datastore` validates against real data |
| Verify import complete | Manual Drush commands or functional test | `get_import_status` in one call |
| Wire up DatastoreService | Read services.yml + source for constructor | `get_service_info('dkan.datastore.service')` |
| React to dataset updates | Grep for EVENT_ constants, trace dispatchers | `list_events` + `get_event_info` |
| Set route permissions | Read .permissions.yml, guess role assignments | `list_permissions` + `check_permissions` |
| Understand resource perspectives | Read ResourceMapper + docs | `resolve_resource` shows all perspectives |

## Value for AI Agents Writing DKAN Modules

The 34-tool suite enables an AI agent to go from zero DKAN knowledge to a functional custom module without leaving the MCP interface. Here's the concrete workflow:

### Service discovery → dependency injection

An agent building a module that queries the datastore can:
1. `list_services` with `module: "datastore"` — discovers `dkan.datastore.service`, `dkan.datastore.query`, etc.
2. `get_service_info` on `dkan.datastore.service` — gets constructor params and public methods with types
3. Write a correct `*.services.yml` with proper argument references and a constructor that type-hints the right classes

No source code reading required. The method signatures include parameter names, types, optionality, and return types — enough to write working service calls.

### Event-driven extension

An agent adding behavior when datasets change:
1. `list_events` — finds `dkan_metastore_dataset_update` dispatched by `LifeCycle`
2. `get_event_info` — sees `DatastoreSubscriber::purgeResources` is already subscribed, understands the existing behavior
3. Writes an EventSubscriber that subscribes to the same event with appropriate priority

The agent knows the event name string, which class dispatches it, and what other subscribers exist — enough to avoid conflicts and wire up the subscriber correctly.

### Permission-aware development

An agent implementing an admin page:
1. `list_permissions` — sees existing DKAN permissions and their patterns
2. `get_permission_info` on a relevant permission — learns which routes use it and which roles have it
3. `check_permissions` — validates the new route's permission exists and is assigned
4. Catches issues like the orphaned `administer dkan` reference before they reach production

### Resource chain tracing

An agent writing code that processes datastore data:
1. `list_datasets` → `list_distributions` — gets the resource_id
2. `resolve_resource` — maps it through source → local_file → local_url perspectives, gets the datastore table name
3. `get_datastore_schema` — gets column definitions for the table
4. `query_datastore` — validates the code works against real data

The agent understands the full lifecycle: how a CSV URL becomes a local file, gets imported into a database table, and is queryable — without reading ResourceMapper, ResourceLocalizer, or any import service code.

### Closed-loop development

The combination of introspection tools (understand the system) and data tools (validate against real data) creates a closed development loop:

1. **Discover** — `list_services`, `list_events`, `list_permissions` reveal the architecture
2. **Understand** — `get_service_info`, `get_event_info`, `resolve_resource` provide implementation details
3. **Build** — write module code using discovered APIs and events
4. **Validate** — `query_datastore`, `get_import_status`, `check_permissions` confirm correctness against the live system

This loop runs entirely through MCP. The agent doesn't need to context-switch between reading documentation, grepping source code, and running Drush commands. Each tool returns structured data optimized for programmatic consumption.

## Conclusion

The 34-tool suite covers both **runtime data access** (what exists in the system) and **runtime architecture introspection** (how the system works). The original 15 tools were a force multiplier for iterative development. The 8 DKAN introspection tools close the remaining gaps for DKAN-specific work. The 6 Drupal-generic tools (`list_entity_types`, `get_entity_fields`, `list_modules`, `get_config`, `list_plugins`, `get_route_info`) and 3 Drupal write tools (`clear_cache`, `enable_module`, `disable_module`) extend coverage to the underlying Drupal platform — an agent can discover entity types, fields, plugins, config, and routes without reading YAML files. Module development no longer requires reading DKAN source code or guessing Drupal identifiers for the most common tasks — the MCP interface provides sufficient architectural knowledge to build, wire up, and validate custom modules against a live DKAN installation.
