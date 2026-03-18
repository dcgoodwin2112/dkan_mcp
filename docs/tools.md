# Tool Reference

Per-tool parameter schemas, response shapes, and behavioral notes for all 52 dkan_mcp tools. For workflow sequences and common mistakes, see [CLAUDE.md](../CLAUDE.md). For overview tables and installation, see [README.md](../README.md).

**Error convention:** All tools return `{"error": "message"}` on failure. Only success responses are documented below.

**ID formats:** Metastore tools accept UUIDs. Datastore tools accept resource IDs in `identifier__version` format. Use `list_distributions` to bridge between them (returns both).

## Metastore

### `list_datasets`

List dataset summaries with pagination.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `offset` | integer | no | 0 | Datasets to skip |
| `limit` | integer | no | 25 | Max datasets (clamped 1-100) |

**Response:** `{datasets: [{identifier, title, description, distributions}], total, offset, limit}`

**Notes:**
- Descriptions truncated to 200 chars
- `distributions` is a count (integer), not the distribution objects
- `total` corrected downward when full result fits in one page (avoids counting invalid items)

### `list_schemas`

List available metadata schema IDs.

*No parameters.*

**Response:** `{schemas: [string]}`

### `get_dataset`

Get full dataset metadata by UUID.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Dataset UUID |

**Response:** `{dataset: {identifier, title, description, distribution, keyword, ...}}` -- full DCAT dataset object with `%-prefixed` internal keys stripped.

### `get_distribution`

Get full distribution metadata by UUID.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Distribution UUID |

**Response:** `{distribution: {identifier, data: {downloadURL, mediaType, title, ...}}}` -- `%-prefixed` keys stripped.

### `list_distributions`

List distributions for a dataset. Bridge between metastore UUIDs and datastore resource IDs.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `dataset_id` | string | yes | -- | Dataset UUID |

**Response:** `{distributions: [{identifier, resource_id, title, mediaType, downloadURL}]}`

**Notes:**
- `resource_id` is extracted from `%Ref:downloadURL` in `identifier__version` format -- pass this to datastore tools
- `identifier` is the distribution UUID from `%Ref:distribution`

### `get_catalog`

Get the full DCAT data catalog.

*No parameters.*

**Response:** `{catalog: {dataset: [{identifier, title, description, ...}], ...}}`

**Notes:**
- Descriptions truncated to 200 chars
- `spatial` field removed to reduce token usage

### `get_schema`

Get a JSON Schema definition by schema ID.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `schema_id` | string | yes | -- | Schema ID (e.g., `dataset`, `distribution`, `keyword`) |

**Response:** `{schema_id, schema: {type, properties, required, ...}}`

**Notes:**
- Use `list_schemas` to discover available schema IDs
- Returns the full JSON Schema object including property definitions, types, and validation constraints

### `get_dataset_info`

Get aggregated dataset lineage: distributions, resources, import status, perspectives.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `uuid` | string | yes | -- | Dataset UUID |

**Response:** `{dataset_info: {latest_revision: {distributions: [{distribution_uuid, resource_id, resource_version, mime_type, source_path, importer_status, importer_percent_done, importer_error, table_name, fetcher_status, fetcher_percent_done, file_path}]}, ...}}`

**Notes:**
- Returns the actual output of `DatasetInfo::gather()` with all plugin-contributed keys
- Use this to discover array structures that `get_class_info` can't reveal (methods returning `array`)
- `importer_status` values: `"waiting"`, `"done"`, `"error"`

## Datastore

### `query_datastore`

Query a datastore resource with filters, sorting, pagination, and aggregation.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Resource ID (`identifier__version`) |
| `columns` | string | no | all | Comma-separated column names |
| `conditions` | string | no | -- | JSON array: `[{"property":"col","value":"val","operator":"="}]` |
| `sort_field` | string | no | -- | Column to sort by |
| `sort_direction` | string | no | `"asc"` | `"asc"` or `"desc"` |
| `limit` | integer | no | 100 | Max rows (clamped 1-500) |
| `offset` | integer | no | 0 | Rows to skip |
| `expressions` | string | no | -- | JSON array: `[{"operator":"sum","operands":["col"],"alias":"total"}]` |
| `groupings` | string | no | -- | Comma-separated GROUP BY columns |

**Response:** `{results: [{col: val, ...}], result_count, total_rows, limit, offset}`

**Notes:**
- Condition operators: `=`, `<>`, `<`, `<=`, `>`, `>=`, `like`, `contains`, `starts with`, `in`, `not in`, `between`
- For `in`/`not in`, value is an array. For `between`, value is `[min, max]`
- Supports `conditionGroup` for OR logic: `[{"groupOperator":"or","conditions":[{"property":"state","value":"CA","operator":"="},{"property":"state","value":"TX","operator":"="}]}]`. Groups can be nested recursively.
- Aggregate expression operators: `sum`, `count`, `avg`, `max`, `min` (1 operand each, use with `groupings`)
- Arithmetic expression operators: `+`, `-`, `*`, `/`, `%` (2 operands each, row-level computed columns)
- Cannot mix aggregate and arithmetic operators in the same query (causes MySQL GROUP BY errors)
- Expression alias must not conflict with any column name in the resource schema, explicit column selections, or grouping names
- Grouping columns are auto-included in results even if not in `columns`
- All non-aggregated columns must appear in `groupings`
- **Known limitations:** HAVING (filter on aggregated values) and DISTINCT are not supported by DKAN's query schema

### `query_datastore_join`

Join and query two datastore resources. Primary aliased as `t`, joined as `j`.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Primary resource ID |
| `join_resource_id` | string | yes | -- | Joined resource ID |
| `join_on` | string | yes | -- | Simple: `"col1=col2"`. JSON: `{"left":"t.col","right":"j.col","operator":"="}` |
| `columns` | string | no | all | Alias-qualified: `"t.state,j.rate"`. Unqualified defaults to `t` |
| `conditions` | string | no | -- | JSON array. Add `"resource":"j"` for joined-table filters. Supports `conditionGroup` for OR logic. |
| `sort_field` | string | no | -- | Column with optional alias prefix (`"j.rate"`) |
| `sort_direction` | string | no | `"asc"` | `"asc"` or `"desc"` |
| `limit` | integer | no | 100 | Max rows (clamped 1-500) |
| `offset` | integer | no | 0 | Rows to skip |
| `expressions` | string | no | -- | JSON array of expressions (same format as `query_datastore`). Aggregate and arithmetic operators supported. |
| `groupings` | string | no | -- | Comma-separated GROUP BY columns with alias prefix: `"t.state,j.year"` |

**Response:** `{results: [{col: val, ...}], result_count, total_rows, limit, offset}`

**Notes:**
- Simple join format: `"state=state_abbreviation"` -- left defaults to `t`, right defaults to `j`
- Qualified format supports `"t.col=j.col"` explicitly
- Unqualified column names default to primary resource (`t`)
- Groupings and expressions work the same as `query_datastore` but columns should be alias-qualified
- Grouped columns are auto-included in results as resource-qualified objects

### `get_datastore_schema`

Get column names and types for a datastore resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Resource ID (`identifier__version`) |

**Response:** `{resource_id, columns: [{name, type, description?}]}`

**Notes:**
- `record_number` column is excluded from output
- `description` key present only when the column has one

### `search_columns`

Search column names/descriptions across all imported datastore resources.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `search_term` | string | yes | -- | Substring to match (case-insensitive) |
| `search_in` | string | no | `"name"` | `"name"`, `"description"`, or `"both"` |
| `limit` | integer | no | 100 | Max matches |

**Response:** `{matches: [{dataset_title, dataset_uuid, resource_id, column_name, column_type, matched_in, column_description?}], total_matches, resources_searched, sampled?, sample_size?}`

**Notes:**
- Samples first 200 datasets; `sampled: true` and `sample_size` present when dataset count exceeds 200
- Only searches resources with `importer_status === "done"`
- `record_number` column excluded
- `column_description` only present when non-empty

### `get_datastore_stats`

Get per-column statistics for a datastore resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Resource ID (`identifier__version`) |
| `columns` | string | no | all | Comma-separated column names to analyze |

**Response:** `{resource_id, total_rows, columns: [{name, type, null_count, distinct_count, min, max}]}`

**Notes:**
- DKAN stores CSV data as text -- `min`/`max` use **lexicographic** ordering (e.g., `"9" > "10000"`). For true numeric min/max, use `query_datastore` with min/max expressions
- `record_number` column excluded
- Returns error if unknown column names are requested

### `get_import_status`

Get import/processing status of a datastore resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Resource ID (`identifier__version`) |

**Response:** `{resource_id, status, num_of_rows, num_of_columns}`

**Notes:**
- `status`: `"done"` (rows > 0), `"pending"` (rows = 0), or `"not_imported"` (on error)
- On error, response includes both `status: "not_imported"` and `error` message
- `num_of_columns` includes the internal `record_number` column, so it will be 1 higher than `get_datastore_schema` reports

## Search

### `search_datasets`

Search datasets by keyword via DKAN's search API.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `keyword` | string | yes | -- | Search term |
| `page` | integer | no | 1 | Page number (1-based) |
| `page_size` | integer | no | 10 | Results per page (clamped 1-50) |

**Response:** `{results: [{identifier, title, description, distributions}], total, page, page_size}`

**Notes:**
- Uses HTTP client internally (hits `/api/1/search` endpoint)
- Descriptions truncated to 200 chars

## Harvest

### `list_harvest_plans`

List all registered harvest plan IDs.

*No parameters.*

**Response:** `{plans: [string], total}`

### `get_harvest_plan`

Get harvest plan configuration.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `plan_id` | string | yes | -- | Harvest plan ID |

**Response:** `{plan: {identifier, extract: {...}, load: {...}, transforms: [...]}}`

### `get_harvest_runs`

List all runs for a harvest plan.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `plan_id` | string | yes | -- | Harvest plan ID |

**Response:** `{runs: [{status: {extract, transform: {class: {uuid: status}}, load: {uuid: status}}, extracted_items_ids: [uuid], orphan_ids: [uuid], identifier}], total}` -- `plan` key removed from each run to reduce size.

### `get_harvest_run_result`

Get detailed result for a specific harvest run.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `plan_id` | string | yes | -- | Harvest plan ID |
| `run_id` | string | no | latest | Run ID/timestamp |

**Response:** `{result: {status: {extract, transform, load}, extracted_items_ids, orphan_ids, identifier}}` -- same structure as `get_harvest_runs` entries. `plan` key removed.

### `register_harvest`

Register a new harvest plan.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `plan` | string | yes | -- | Harvest plan as a JSON string |

**Response:** `{status: "success", plan_id, message}`

**Notes:**
- Plan JSON must be an object with `identifier`, `extract` (with `type` and `uri`), and `load` (with `type`) properties
- `extract.type`: typically `\Harvest\ETL\Extract\DataJson`
- `load.type`: typically `\Drupal\harvest\Load\Dataset`
- Re-registering an existing plan ID overwrites silently
- Validates JSON structure before calling DKAN; returns `{error}` for invalid JSON, non-object, or missing required properties

### `run_harvest`

Execute a harvest run for a registered plan.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `plan_id` | string | yes | -- | Harvest plan ID |

**Response:** `{status: "success", plan_id, result: {status: {extract, transform, load}, extracted_items_ids, orphan_ids, identifier}, message}`

**Notes:**
- Returns `{error: "Harvest plan not found: {id}"}` if plan doesn't exist
- `result` contains the full harvest run output including per-dataset load status (`NEW`, `UPDATED`, `UNCHANGED`)
- Runs synchronously — may take time for large source catalogs

### `deregister_harvest`

Remove a registered harvest plan.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `plan_id` | string | yes | -- | Harvest plan ID |

**Response:** `{status: "success", plan_id, message}` or `{status: "not_found", plan_id, message}`

**Notes:**
- Does not delete datasets that were previously harvested by this plan
- Returns `not_found` if plan doesn't exist

## Introspection

### `list_services`

List DKAN service IDs with class names.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `module` | string | no | all | Module filter: `metastore`, `datastore`, `harvest`, `common` |

**Response:** `{services: [{id, class}], total}`

**Notes:**
- Filters by `dkan.{module}.` prefix. Omit for all `dkan.*` services.

### `get_service_info`

Get service class, constructor dependencies, method signatures, and YAML definition.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `service_id` | string | yes | -- | Drupal service ID (e.g., `dkan.metastore.service`) |

**Response:** `{service_id, class, constructor_params: [{name, type, optional?}], methods: [{name, params: [{name, type, optional?}], return_type}], yaml_definition?: {arguments?, calls?, tags?}}`

**Notes:**
- `yaml_definition` reveals setter injection (`calls`) and service tags not visible from constructor reflection
- Methods listed are only those declared in the service's own class (not inherited)
- Methods starting with `_` are excluded

### `get_class_info`

Get full public API of any PHP class or interface.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `class_name` | string | yes | -- | Fully-qualified class name (e.g., `Drupal\datastore\Storage\DatabaseTable`) |

**Response:** `{class, is_abstract, is_interface, parent, interfaces: [string], methods: [{name, params: [{name, type, optional?}], return_type, declared_in}]}`

**Notes:**
- `declared_in` shows which class/interface declares each method
- Methods starting with `_` are excluded

### `list_events`

List DKAN event constants with string values and declaring classes.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `module` | string | no | all | Module filter: `metastore`, `datastore`, `common`, etc. |

**Response:** `{events: [{constant, event_name, declaring_class, module}], total}`

### `get_event_info`

Get event details, subscribers, event class, and dispatch payload type.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `event_name` | string | yes | -- | Event name string (e.g., `dkan_metastore_dataset_update`) |

**Response:** `{constant, event_name, declaring_class, module, subscribers: [{class, method}], event_class?, event_methods?: [{name, params, return_type}], dispatch_payload?: {type, dispatch_site, methods}}`

**Notes:**
- `event_class` resolved from subscriber parameter type hints
- `dispatch_payload` present for events using `Drupal\common\Events\Event` where `getData()` returns `mixed` -- documents the actual type at the dispatch site
- `dispatch_payload.methods` lists the public API of the payload type

### `list_permissions`

List DKAN permissions with metadata.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `module` | string | no | all | Module provider filter |

**Response:** `{permissions: [{name, title, description, provider, restrict_access}], total}`

### `get_permission_info`

Get permission definition, routes requiring it, and roles holding it.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `permission` | string | yes | -- | Permission machine name (e.g., `harvest_api_index`) |

**Response:** `{permission, definition: {title, description, provider, restrict_access}, routes: [{route_name, path, methods, permission_expression}], roles: [{id, label}]}`

### `check_permissions`

Detect DKAN permission misconfigurations.

*No parameters.*

**Response:** `{orphaned_route_permissions: [{permission, route_name, path}], unused_permissions: [{permission, provider, note?}], orphaned_role_permissions: [{permission, role_id, role_label}], summary: {total_issues}}`

**Notes:**
- Detects three types: permissions in routes but not defined, defined but unused, assigned to roles but not defined
- Entity access permissions (e.g., `view any data`) get a `note` explaining no route usage is expected
- `total_issues` excludes entity access permissions from the count

## Resource

### `resolve_resource`

Trace the full reference chain for a resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `id` | string | yes | -- | Distribution UUID or resource ID (`identifier__version`) |

**Response:** `{distribution_uuid, resource_identifier, resource_version, resource_id, dataset_uuid, perspectives: [{perspective, file_path, mime_type}], datastore_table, import_status}`

**Notes:**
- Accepts both UUID and `identifier__version` format
- `perspectives`: `source`, `local_file`, `local_url` -- shows file paths at each stage
- `dataset_uuid` found via brute-force iteration over all datasets (reverse lookup)
- `import_status`: `"done"`, `"pending"`, or `"not_imported"`
- `distribution_uuid` is `null` when input was in `identifier__version` format

## Write

### `clear_cache`

Flush all Drupal caches.

*No parameters.*

**Response:** `{status: "success", message}`

**Notes:**
- Does not rebuild the service container -- restart MCP server after `services.yml` changes

### `enable_module`

Enable a Drupal module.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `module_name` | string | yes | -- | Module machine name |

**Response:** `{status, message}` -- `status`: `"success"` or `"already_enabled"`

### `disable_module`

Uninstall a Drupal module.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `module_name` | string | yes | -- | Module machine name |

**Response:** `{status, message}` -- `status`: `"success"` or `"not_enabled"`

### `create_test_dataset`

Create a minimal dataset with one CSV distribution.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `title` | string | yes | -- | Dataset title |
| `download_url` | string | yes | -- | URL to a CSV file |

**Response:** `{status: "success", identifier, message}`

**Notes:**
- Creates and publishes the dataset immediately
- Distribution references may need cron to fully resolve
- Follow up: `list_distributions` -> `import_resource` -> `query_datastore`

### `import_resource`

Trigger datastore import for a resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Resource ID (`identifier__version`) |
| `deferred` | boolean | no | `false` | Queue for background processing |

**Response:** `{status, resource_id, import_result, errors, message}`

**Notes:**
- Synchronous by default (suitable for small CSVs)
- `deferred: true` queues for background processing -- use `get_queue_status` and `get_import_status` to monitor
- `status`: `"success"` or `"error"`
- `errors` is `null` when no errors occurred

### `update_dataset`

Full replacement of dataset metadata (PUT semantics).

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Dataset UUID |
| `metadata` | string | yes | -- | Complete dataset metadata as JSON string |

**Response:** `{status, identifier, new}` -- `new: true` if dataset was created (upsert).

**Notes:**
- Can upsert: creates if dataset doesn't exist
- Validates JSON is an object (not scalar/array)
- Returns `{status: "unmodified", identifier, message}` if no changes detected

### `patch_dataset`

Partial update via JSON Merge Patch (RFC 7396).

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Dataset UUID |
| `metadata` | string | yes | -- | JSON object with only fields to change |

**Response:** `{status: "success", identifier, message}` or `{status: "not_found", ...}`

**Notes:**
- Only send fields you want to change; omitted fields are preserved
- Validates JSON is an object

### `delete_dataset`

Delete a dataset and cascade-delete distributions and datastore tables.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Dataset UUID |

**Response:** `{status: "success", identifier, message}` or `{status: "not_found", ...}`

**Notes:**
- Destructive and irreversible
- Cascade-deletes associated distributions and datastore tables

### `publish_dataset`

Publish a dataset to make it publicly visible.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Dataset UUID |

**Response:** `{status: "success", identifier, message}` or `{status: "not_found", identifier, message}`

**Notes:**
- Idempotent — publishing an already-published dataset returns success
- Dataset must exist; returns `not_found` otherwise

### `unpublish_dataset`

Unpublish (archive) a dataset to remove it from public visibility.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `identifier` | string | yes | -- | Dataset UUID |

**Response:** `{status: "success", identifier, message}` or `{status: "not_found", identifier, message}`

**Notes:**
- Idempotent — unpublishing an already-archived dataset returns success
- Dataset is not deleted, only hidden from public-facing queries (`get_dataset` defaults to published only)
- DKAN calls this "archive" internally

### `drop_datastore`

Drop the datastore table for a resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resource_id` | string | yes | -- | Resource ID (`identifier__version`) |

**Response:** `{status: "success", resource_id, message}`

**Notes:**
- Removes the database table backing the imported CSV data
- Use `import_resource` afterward to re-import if needed
- Returns error if the datastore table doesn't exist (already dropped or never imported)

## Status

### `get_site_status`

Get high-level DKAN site overview.

*No parameters.*

**Response:** `{datasets: {total, retrievable?, invalid?}, distributions: {total, by_format: {csv: N, ...}}, imports: {done, pending, error}, harvest: {plans}, dkan: {version, modules: {metastore: "enabled", ...}}, drupal: {version}, sampled?, sample_size?}`

**Notes:**
- Samples first 100 datasets for distribution/import stats; `sampled: true` when total exceeds 100
- Format extracted from `mediaType` subtype (e.g., `text/csv` -> `csv`)
- `retrievable`/`invalid` only present when some datasets fail validation

### `get_queue_status`

Get queue item counts for DKAN queues.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `queue_name` | string | no | all | Specific queue name (e.g., `datastore_import`) |

**Response:** `{queues: [{name, items, title, cron_time?, lease_time?}]}`

**Notes:**
- Without `queue_name`, returns all queues from DKAN modules (datastore, metastore, common, harvest)
- `cron_time` and `lease_time` present only when defined in the queue worker plugin

## Drupal

### `list_entity_types`

List Drupal entity types with bundles.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `group` | string | no | all | `"content"` or `"configuration"` |

**Response:** `{entity_types: [{id, label, class, group, entity_keys: {id, ...}, storage_class, bundles: [{id, label}]}], total}`

### `get_entity_fields`

Get field definitions for an entity type/bundle.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `entity_type_id` | string | yes | -- | Entity type ID (e.g., `node`, `user`) |
| `bundle` | string | no | -- | Bundle name. Omit for base fields only |

**Response:** `{fields: [{name, type, label, required, cardinality, description, is_base_field}], total}`

**Notes:**
- Auto-resolves bundle for non-bundleable entities (e.g., `user` -> bundle `user`)
- Without bundle on bundleable entities, returns base fields only
- Validates bundle exists and lists valid bundles in error message

### `list_modules`

List enabled Drupal modules with metadata.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `name_contains` | string | no | all | Substring filter on machine name |

**Response:** `{modules: [{name, human_name, version, package, path, dependencies}], total}`

**Notes:**
- `version` is `null` for modules installed via Composer path repos or without explicit version info

### `get_config`

Get Drupal configuration values or list config names.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | no | -- | Full config name (e.g., `system.site`) |
| `prefix` | string | no | -- | Config name prefix to list (e.g., `system.`) |

**Response (name):** `{config_name, data: {...}}` -- `_core` key stripped.

**Response (prefix):** `{config_names: [string], total}`

**Notes:**
- Provide `name` OR `prefix`, not both
- Returns error if neither provided

### `list_plugins`

List plugin definitions by type.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | string | yes | -- | Plugin type (e.g., `block`, `field.field_type`, `queue_worker`) |

**Response:** `{plugins: [{id, label?, class?, provider?, category?}], total}`

**Notes:**
- Resolves to `plugin.manager.{type}` service
- Optional keys present only when defined in the plugin definition

### `get_route_info`

Get route details by name or path pattern.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `route_name` | string | no | -- | Exact route name |
| `path` | string | no | -- | Path pattern to search (e.g., `/api/1/`) |

**Response:** `{routes: [{name, path, methods, controller?: {type, value}, requirements?: {_permission?, ...}, admin_route?, parameters?}], total?}`

**Notes:**
- Provide `route_name` OR `path`, not both
- `controller.type`: `_controller`, `_form`, `_entity_form`, or `_entity_list`
- `total` only present for path pattern searches
- `admin_route` only present when true

## Logs

### `get_recent_logs`

Get recent watchdog log entries with optional filters.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | string | no | all | Log type (e.g., `"dkan"`, `"php"`, `"user"`) |
| `severity` | integer | no | all | Max severity 0-7 (returns this level and more severe) |
| `limit` | integer | no | 25 | Max entries (clamped 1-100) |
| `offset` | integer | no | 0 | Pagination offset |

**Response:** `{entries: [{wid, type, severity, severity_label, message, timestamp, location, uid}], total, limit, offset}`

**Notes:**
- Requires `dblog` module (returns error if not enabled)
- Severity follows RFC 5424: 0=Emergency, 1=Alert, 2=Critical, 3=Error, 4=Warning, 5=Notice, 6=Info, 7=Debug
- Messages have variables interpolated (rendered, not raw placeholders)
- Ordered by `wid` descending (most recent first)

### `get_log_types`

List distinct watchdog log types with entry counts.

*No parameters.*

**Response:** `{types: [{type, count}]}` -- ordered by count descending.

**Notes:**
- Requires `dblog` module
