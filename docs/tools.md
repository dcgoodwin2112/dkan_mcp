# Tool Reference

> **Historical snapshot — legacy `dkan_mcp` module.** Documents the 35 tools of
> the original bespoke server. The current server is `dkan_mcp_server` (38 tools:
> 25 read, 13 write); see its `README.md`. Do not use these counts as a
> validation target for the current module.

Per-tool parameter schemas, response shapes, and behavioral notes for all 35 dkan_mcp tools. For workflow sequences and common mistakes, see [AGENTS.md](../AGENTS.md). For overview tables and installation, see [README.md](../README.md).

**Error convention:** All tools return `{"error": "message"}` on failure. Only success responses are documented below.

**Parameter naming:** Tool input parameters use camelCase (e.g. `resourceId`, `sortField`, `datasetId`). Response payloads use the snake_case keys shown in each **Response** line.

**ID formats:** Metastore tools accept UUIDs. Datastore tools accept resource IDs in `identifier__version` format; `get_datastore_schema` and `get_datastore_stats` also accept a distribution UUID (resolved via the metastore). Use `list_distributions` to bridge between UUIDs and resource IDs (returns both).

**Provenance:** Most tools map directly to DKAN REST endpoints (`/api/1/...`): metastore item read/write, search, harvest plans/runs, datastore query/join, import status, datastore drop, resource import. The following are agent-convenience value-adds with no single REST equivalent: `get_dataset_info` (wraps `DatasetInfo::gather`), `resolve_resource` (multi-service lineage + reverse dataset lookup), `search_columns` (cross-catalog column search), `get_datastore_stats` (SQL aggregation), and the data-dictionary enrichment merged into `get_datastore_schema`.

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
| `datasetId` | string | yes | -- | Dataset UUID |

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
| `schemaId` | string | yes | -- | Schema ID (e.g., `dataset`, `distribution`, `keyword`) |

**Response:** `{schema_id, schema: {type, properties, required, ...}}`

**Notes:**
- Use `list_schemas` to discover available schema IDs
- Returns the full JSON Schema object including property definitions, types, and validation constraints

### `get_data_dictionary`

Get the data dictionary linked to a dataset or distribution.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `datasetOrResourceId` | string | yes | -- | Dataset UUID or resource ID (`identifier__version`) |

**Response:** `{dictionaries: {<resource_id>: {identifier, url, title, fields: [{name, type, title?, description?}]}}}` or `{error}`

**Notes:**
- A dataset UUID returns dictionaries for all linked distributions; a resource ID returns the single dictionary for that distribution.
- `get_datastore_schema` already merges per-column dictionary fields into its column response. Use this tool when you need the full dictionary (including fields the datastore doesn't expose) or when the distribution has not been imported.
- Returns `error` when the dataset has no linked dictionary, the resource cannot be matched to any distribution, or the linked dictionary item cannot be fetched.
- Walks up to 200 datasets when looking up by `resource_id`. Datasets beyond that cap will not be matched.

### `get_dataset_info`

Get aggregated dataset lineage: distributions, resources, import status, perspectives.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `uuid` | string | yes | -- | Dataset UUID |

**Response:** `{dataset_info: {latest_revision: {distributions: [{distribution_uuid, resource_id, resource_version, mime_type, source_path, importer_status, importer_percent_done, importer_error, table_name, fetcher_status, fetcher_percent_done, file_path}]}, ...}}`

**Notes:**
- Returns the actual output of `DatasetInfo::gather()` with all plugin-contributed keys
- Use this to discover the actual array structure returned by `DatasetInfo::gather()` (a method typed `array`) rather than guessing keys
- `importer_status` values: `"waiting"`, `"done"`, `"error"`

## Datastore

### `query_datastore`

Query a datastore resource with filters, sorting, pagination, and aggregation.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resourceId` | string | yes | -- | Resource ID (`identifier__version`) |
| `columns` | string | no | all | Comma-separated column names |
| `conditions` | string | no | -- | JSON array: `[{"property":"col","value":"val","operator":"="}]` |
| `sortField` | string | no | -- | Column to sort by |
| `sortDirection` | string | no | `"asc"` | `"asc"` or `"desc"` |
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
| `resourceId` | string | yes | -- | Primary resource ID |
| `joinResourceId` | string | yes | -- | Joined resource ID |
| `joinOn` | string | yes | -- | Simple: `"col1=col2"`. JSON: `{"left":"t.col","right":"j.col","operator":"="}` |
| `columns` | string | no | all | Alias-qualified: `"t.state,j.rate"`. Unqualified defaults to `t` |
| `conditions` | string | no | -- | JSON array. Add `"resource":"j"` for joined-table filters. Supports `conditionGroup` for OR logic. |
| `sortField` | string | no | -- | Column with optional alias prefix (`"j.rate"`) |
| `sortDirection` | string | no | `"asc"` | `"asc"` or `"desc"` |
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
| `resourceId` | string | yes | -- | Resource ID (`identifier__version`) or a distribution UUID |

**Response:** `{resource_id, columns: [{name, type, description?, dictionary_title?, dictionary_description?, dictionary_type?}], dictionary_identifier?, dictionary_url?}`

**Notes:**
- `record_number` column is excluded from output
- `description` key present only when the column has one
- When the distribution links to a data dictionary (`describedBy`), per-column `dictionary_title` / `dictionary_description` / `dictionary_type` are merged in (publisher-curated values, distinct from the DB-derived `type`); root-level `dictionary_identifier` and `dictionary_url` are added so callers can deep-link or fetch the full dictionary
- Dictionary lookup is best-effort — fetch failures degrade silently to the original DB-only response shape

### `search_columns`

Search column names/descriptions across all imported datastore resources.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `searchTerm` | string | yes | -- | Substring to match (case-insensitive) |
| `searchIn` | string | no | `"name"` | `"name"`, `"description"`, or `"both"` |
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
| `resourceId` | string | yes | -- | Resource ID (`identifier__version`) or a distribution UUID |
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
| `resourceId` | string | yes | -- | Resource ID (`identifier__version`) |

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
| `pageSize` | integer | no | 10 | Results per page (clamped 1-50) |

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
| `planId` | string | yes | -- | Harvest plan ID |

**Response:** `{plan: {identifier, extract: {...}, load: {...}, transforms: [...]}}`

### `get_harvest_runs`

List all runs for a harvest plan.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `planId` | string | yes | -- | Harvest plan ID |

**Response:** `{runs: [{status: {extract, transform: {class: {uuid: status}}, load: {uuid: status}}, extracted_items_ids: [uuid], orphan_ids: [uuid], identifier}], total}` -- `plan` key removed from each run to reduce size.

### `get_harvest_run_result`

Get detailed result for a specific harvest run.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `planId` | string | yes | -- | Harvest plan ID |
| `runId` | string | no | latest | Run ID/timestamp |

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
| `planId` | string | yes | -- | Harvest plan ID |

**Response:** `{status: "success", plan_id, result: {status: {extract, transform, load}, extracted_items_ids, orphan_ids, identifier}, message}` or `{status: "not_found", plan_id, message}`

**Notes:**
- Returns `{status: "not_found", plan_id, message}` if the plan doesn't exist
- `result` contains the full harvest run output including per-dataset load status (`NEW`, `UPDATED`, `UNCHANGED`)
- Runs synchronously — may take time for large source catalogs

### `deregister_harvest`

Remove a registered harvest plan.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `planId` | string | yes | -- | Harvest plan ID |

**Response:** `{status: "success", plan_id, message}` or `{status: "not_found", plan_id, message}`

**Notes:**
- Does not delete datasets that were previously harvested by this plan
- Returns `not_found` if plan doesn't exist

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

### `import_resource`

Trigger datastore import for a resource.

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `resourceId` | string | yes | -- | Resource ID (`identifier__version`) |
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

### `post_metastore_item`

Create a metastore item under any schema (e.g., `data-dictionary`, `distribution`, `theme`, `keyword`).

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `schemaId` | string | yes | -- | Metastore schema ID (e.g., `data-dictionary`) |
| `metadata` | string | yes | -- | Item metadata as JSON object string. Must include `identifier` and the schema's required fields. |

**Response:** `{status: "success", schema_id, identifier, message}`, `{status: "already_exists", schema_id, message}`, or `{error}`

**Notes:**
- For datasets prefer `update_dataset`
- Use `list_schemas` and `get_schema(schemaId)` to discover required fields
- For data dictionaries, body is `{identifier, data: {title, fields: [{name, type, title?, description?}]}}`. Link to a distribution via `patch_dataset` setting `distribution[i].data.describedBy` (URL to the dictionary item) and `describedByType: "application/vnd.tableschema+json"`. Requires `metastore.settings.data_dictionary_mode = "reference"`.

### `patch_metastore_item`

Partial update of any metastore item via JSON Merge Patch (RFC 7396).

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `schemaId` | string | yes | -- | Metastore schema ID |
| `identifier` | string | yes | -- | Item identifier (UUID) |
| `metadata` | string | yes | -- | JSON object with only fields to change |

**Response:** `{status: "success", schema_id, identifier, message}` or `{status: "not_found", ...}`

**Notes:**
- For datasets prefer `patch_dataset`
- Only send fields you want to change; omitted fields are preserved

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
| `resourceId` | string | yes | -- | Resource ID (`identifier__version`) |

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
| `queueName` | string | no | all | Specific queue name (e.g., `datastore_import`) |

**Response:** `{queues: [{name, items, title, cron_time?, lease_time?}]}`

**Notes:**
- Without `queueName`, returns all queues from DKAN modules (datastore, metastore, common, harvest)
- `cron_time` and `lease_time` present only when defined in the queue worker plugin
