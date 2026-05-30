# DKAN MCP

MCP server module that exposes a running DKAN site's data catalog, datastore, search, and harvest operations to MCP-capable AI agents via the [Model Context Protocol](https://modelcontextprotocol.io). 35 tools: 23 read-only for catalog/datastore/search discovery and querying (including data-dictionary access and dictionary-enriched datastore schemas), and 12 write tools for dataset lifecycle, metastore item management, datastore imports, and harvest operations.

## Why This Module Exists

DKAN is a Drupal distribution for open data. Its catalog metadata, datastore tables, search index, and harvest pipeline are spread across services, the database, and runtime state — none of it directly visible to an AI agent.

dkan_mcp gives an agent direct, structured access to a running DKAN site so it can:

- **Discover the catalog** — list and read datasets, distributions, schemas, and data dictionaries.
- **Query live data** — run filtered, sorted, aggregated, and joined queries against datastore tables; inspect column schemas and per-column statistics.
- **Trace data lineage** — map a distribution or resource ID through its perspectives to the datastore table and owning dataset.
- **Administer DKAN data** — create, update, patch, publish/unpublish, and delete datasets and metastore items; trigger and drop datastore imports; register, run, and deregister harvests.
- **Check operational state** — site-level dataset/import/harvest summary and DKAN queue depths.

Each tool returns structured data optimized for programmatic consumption.

> **Scope note:** Generic Drupal/developer introspection (services, events, permissions, entity/plugin/config/route discovery, watchdog logs) and generic Drupal admin (cache clear, module enable/disable) are intentionally **not** part of this module — an agent running locally already has `drush` for those. See [`../../../../dkan-mcp-removed-tools.md`](../../../../dkan-mcp-removed-tools.md) for the removal rationale and candidates for future skills/docs.

## Requirements

- Drupal 10.2+ or 11
- DKAN (metastore, datastore, harvest modules enabled)
- `dkan_query_tools` module enabled (provides the catalog/datastore/search tool classes shared with `dkan_drupal_ai_query`)
- `mcp/sdk ^0.4` (a normal Composer dependency, resolved at site level)

## Installation

1. Install the `dkan_query_tools` module first — it provides the catalog/datastore/search tool classes that dkan_mcp injects into its MCP tools. See [dkan_query_tools README](../dkan_query_tools/README.md); in short:

```json
{
  "repositories": {
    "dkan_query_tools": { "type": "vcs", "url": "https://github.com/dcgoodwin2112/dkan_query_tools.git" }
  },
  "require": {
    "dcgoodwin2112/dkan_query_tools": "dev-main"
  }
}
```

```bash
composer update dcgoodwin2112/dkan_query_tools
drush en dkan_query_tools
```

2. Add dkan_mcp as a Composer dependency. Its `composer.json` requires `mcp/sdk ^0.4`, which Composer resolves into the site-level `vendor/` like any other dependency — no module-local vendor, no post-install steps:

```json
{
  "repositories": {
    "dkan_mcp": { "type": "vcs", "url": "https://github.com/dcgoodwin2112/dkan_mcp.git" }
  },
  "require": {
    "dcgoodwin2112/dkan_mcp": "dev-main"
  }
}
```

```bash
composer update dcgoodwin2112/dkan_mcp
drush en dkan_mcp
```

Drupal auto-enables `dkan_query_tools` as a dependency if it isn't already, but the Composer step in (1) must happen first so the package is on disk.

## MCP Client Configuration

Two transports are available:

| Transport | Endpoint | Tools | Auth | Use Case |
|---|---|---|---|---|
| **stdio** | `drush dkan-mcp:serve` | All 35 | Drupal session (inherited) | Local development with an MCP-capable agent |
| **HTTP** | `POST /mcp` | 22 read-only | `access content` permission | Remote clients, browser-based tools, external agents |

### stdio (all tools)

Add a `.mcp.json` to the project root:

```json
{
  "mcpServers": {
    "dkan": {
      "type": "stdio",
      "command": "ddev",
      "args": ["drush", "dkan-mcp:serve"]
    }
  }
}
```

### HTTP (read-only subset)

The HTTP endpoint exposes 22 data-consumer tools at `/mcp` using the MCP [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) transport. All requests use JSON-RPC 2.0.

```json
{
  "mcpServers": {
    "dkan": {
      "type": "streamable-http",
      "url": "https://dkan-site.ddev.site/mcp"
    }
  }
}
```

**Included tool groups**: metastore (8), datastore (6), search (1), harvest read (4), resource (1), status (2).

**Excluded**: all write tools (dataset/metastore lifecycle, imports, datastore drop), harvest write tools, and `get_dataset_info` (its own `metastore_dev` group — a heavier aggregation kept stdio-only).

**Session management**: the endpoint uses file-based sessions. Clients must send the `Mcp-Session-Id` header (returned by `initialize`) on subsequent requests.

**CORS**: enabled for all origins on the `/mcp` path.

## Tools

For full per-tool parameter schemas, response shapes, and behavioral notes, see [docs/tools.md](docs/tools.md). For workflow sequences and common mistakes, see [AGENTS.md](AGENTS.md).

### Metastore

| Tool | Parameters | Description |
|---|---|---|
| `list_datasets` | `offset?`, `limit?` | Dataset summaries with pagination |
| `get_dataset` | `identifier` | Full dataset metadata by UUID |
| `list_distributions` | `datasetId` | Distributions for a dataset (includes `resource_id`) |
| `get_distribution` | `identifier` | Distribution metadata by UUID |
| `list_schemas` | — | Available metadata schema IDs |
| `get_catalog` | — | Full DCAT catalog |
| `get_schema` | `schemaId` | JSON Schema definition by schema ID |
| `get_data_dictionary` | `datasetOrResourceId` | Data dictionary linked to a dataset/distribution (curated field titles, descriptions, declared types) |
| `get_dataset_info` | `uuid` | Aggregated lineage: distributions, resources, import status, perspectives (stdio only — `metastore_dev` group) |

### Datastore

| Tool | Parameters | Description |
|---|---|---|
| `query_datastore` | `resourceId`, `columns?`, `conditions?`, `sortField?`, `sortDirection?`, `limit?`, `offset?`, `expressions?`, `groupings?` | Query with filters, sorting, pagination, aggregation (sum, count, avg, max, min with GROUP BY) |
| `query_datastore_join` | `resourceId`, `joinResourceId`, `joinOn`, `columns?`, `conditions?`, `sortField?`, `sortDirection?`, `limit?`, `offset?`, `expressions?`, `groupings?` | Join two resources on a shared column |
| `get_datastore_schema` | `resourceId` | Column names and types (accepts `identifier__version` or a distribution UUID). Per-column `dictionary_title` / `dictionary_description` / `dictionary_type` and root-level `dictionary_identifier` / `dictionary_url` are merged in when the distribution links to a data dictionary |
| `search_columns` | `searchTerm`, `searchIn?`, `limit?` | Search column names/descriptions across all imported resources |
| `get_datastore_stats` | `resourceId`, `columns?` | Per-column statistics: null count, distinct count, min, max, total rows |
| `get_import_status` | `resourceId` | Import/processing status |

### Search

| Tool | Parameters | Description |
|---|---|---|
| `search_datasets` | `keyword`, `page?`, `pageSize?` | Keyword search across datasets |

### Harvest

| Tool | Parameters | Description |
|---|---|---|
| `list_harvest_plans` | — | All registered harvest plan IDs |
| `get_harvest_plan` | `planId` | Plan config: source URL, extract/transform/load settings |
| `get_harvest_runs` | `planId` | All runs with timestamps and status |
| `get_harvest_run_result` | `planId`, `runId?` | Detailed run result (latest if `runId` omitted) |
| `register_harvest` | `plan` | Register a new harvest plan (JSON string) |
| `run_harvest` | `planId` | Execute a harvest run for a registered plan |
| `deregister_harvest` | `planId` | Remove a registered harvest plan |

### Resource

| Tool | Parameters | Description |
|---|---|---|
| `resolve_resource` | `id` | Trace distribution UUID or resource_id → perspectives → datastore table, import status, owning `dataset_uuid` |

### Write

| Tool | Parameters | Description |
|---|---|---|
| `import_resource` | `resourceId`, `deferred?` | Trigger datastore import for a resource |
| `update_dataset` | `identifier`, `metadata` | Full replacement of dataset metadata (PUT semantics; upserts) |
| `patch_dataset` | `identifier`, `metadata` | Partial update via JSON Merge Patch (RFC 7396) |
| `post_metastore_item` | `schemaId`, `metadata` | Create a metastore item under any schema (data-dictionary, distribution, theme, keyword, etc.) |
| `patch_metastore_item` | `schemaId`, `identifier`, `metadata` | Partial update of any metastore item via JSON Merge Patch |
| `delete_dataset` | `identifier` | Delete a dataset and cascade-delete distributions and datastore tables |
| `publish_dataset` | `identifier` | Publish a dataset to make it publicly visible |
| `unpublish_dataset` | `identifier` | Unpublish (archive) a dataset |
| `drop_datastore` | `resourceId` | Drop the datastore table for a resource |

### Status

| Tool | Parameters | Description |
|---|---|---|
| `get_site_status` | — | Site overview: dataset/distribution counts, import status, harvest plans, DKAN/Drupal versions |
| `get_queue_status` | `queueName?` | Queue item counts for DKAN queues (import, localization, cleanup) |

## Resource ID Formats

Metastore tools use **dataset/distribution UUIDs** (e.g., `b230fcde-aaf0-4cf5-a6f0-788fef498927`).

Datastore tools use **resource IDs** in the format `{identifier}__{version}` (e.g., `3a187a87dc6cd47c48b6b4c4785224b7__1773329007`). Get these from `list_distributions`, which returns a `resource_id` field extracted from the distribution's `%Ref:downloadURL` metadata.

## Architecture

- **Entry points**: `McpServeCommand` (Drush, stdio) and `McpController` (HTTP, Streamable HTTP transport) → `McpServerFactory` → `Mcp\Server`.
- **Tool subsetting**: `McpServerFactory::create()` accepts an optional `$toolGroups` array. `NULL` registers all 35 tools (stdio default). The HTTP controller passes a read-only subset of 22 tools.
- **Tool classes** (7 total):
  - From `dkan_query_tools` (shared with `dkan_drupal_ai_query`): `MetastoreTools`, `DatastoreTools`, `SearchTools`.
  - dkan_mcp-specific: `HarvestTools`, `ResourceTools`, `WriteTools`, `StatusTools`.
  - All are Drupal services with constructor-injected DKAN dependencies.
- **MCP SDK**: `mcp/sdk ^0.4`, a normal site-level Composer dependency. DKAN 4.x and the SDK both require `opis/json-schema ^2`, so there is no version conflict and no isolation layer.

See [ARCHITECTURE.md](ARCHITECTURE.md) for request flow and component detail.

## Development

### Running Tests

Run the module's unit tests with the site-level PHPUnit:

```bash
cd docroot/modules/custom/dkan_mcp && ../../../../vendor/bin/phpunit
```

Tests for the shared `MetastoreTools`, `DatastoreTools`, and `SearchTools` classes live in `dkan_query_tools`.

### Adding a Tool

1. Add a method to the appropriate tool class:
   - For catalog/metastore/datastore/search operations, edit the relevant class in `dkan_query_tools/src/Tool/` (shared with other consumers).
   - For MCP-server-specific tools (harvest, resource, write, status), edit `dkan_mcp/src/Tools/`.
2. Add a spec entry (`name`, `class`, `method`, `readOnly`, `description`; optional `input`/`output` schemas) to the relevant group in the `McpServerFactory::TOOL_GROUPS` constant. A new tool class also needs a service definition, a constructor injection, and an entry in `McpServerFactory::toolContainer()`.
3. If it should be exposed over HTTP, ensure its group is listed in `McpController::HTTP_TOOL_GROUPS`.
4. Add a test in the corresponding module's `tests/src/Unit/`.
