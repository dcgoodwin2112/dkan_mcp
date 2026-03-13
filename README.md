# DKAN MCP

MCP server module for Drupal that exposes DKAN's data catalog, datastore, and internal architecture to AI coding agents (Claude Code, Cursor, Windsurf, etc.) via the [Model Context Protocol](https://modelcontextprotocol.io). All 23 tools are read-only.

## Why This Module Exists

DKAN is a complex system. Building a custom module requires understanding its service layer, event system, permission model, resource reference chain, and datastore internals. That knowledge lives across services.yml files, source code, and runtime state — none of it visible to an AI agent by default.

dkan_mcp bridges that gap. It gives your AI agent direct access to a running DKAN site so it can discover how the system works and validate code against real data, all without leaving the conversation.

### What an agent can do with these tools

**Understand the architecture** — `list_services` and `get_service_info` return every DKAN service ID, constructor dependencies, and public method signatures with types. An agent can write correct dependency injection and service calls without reading source code.

**Find integration points** — `list_events` and `get_event_info` expose all DKAN event constants, which classes dispatch them, and what subscribers are already registered. An agent can build an EventSubscriber that hooks into dataset updates, resource imports, or search queries knowing exactly what events exist and what's already listening.

**Implement access control** — `list_permissions`, `get_permission_info`, and `check_permissions` provide permission definitions, route bindings, and role assignments. `check_permissions` can catch misconfigurations (orphaned route permissions, deprecated permissions) before they reach production.

**Trace the resource lifecycle** — `resolve_resource` maps a resource_id through all three perspectives (source → local_file → local_url), returns the datastore table name, and reports import status. This replaces manually tracing the ResourceMapper → ResourceLocalizer → datastore import chain.

**Query live data** — `query_datastore`, `get_datastore_schema`, and the metastore tools let an agent validate its code against actual datasets, table schemas, and import states on the running site.

### The development loop

These tools enable a closed-loop workflow: discover services and events → understand their APIs → write module code → validate against the live system. The agent doesn't need to context-switch between reading docs, grepping source, and running Drush commands. Each tool returns structured data optimized for programmatic consumption.

See [docs/mcp-tools-evaluation.md](docs/mcp-tools-evaluation.md) for a detailed assessment of each tool's development value.

## Requirements

- Drupal 10.2+ or 11
- DKAN (metastore, datastore, harvest modules enabled)
- MCP SDK installed in module vendor (see Installation)

## Installation

1. Add as a Composer dependency (path repo or VCS):

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

2. Install the MCP SDK in the module's own vendor directory (required due to an `opis/json-schema` version conflict with DKAN):

```bash
cd dkan_mcp && composer require mcp/sdk:^0.4 && composer run-script post-install-cleanup
```

3. Enable the module:

```bash
drush en dkan_mcp
```

## MCP Client Configuration

### Claude Code

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

Or add via CLI:

```bash
claude mcp add --transport stdio dkan --scope project -- ddev drush dkan-mcp:serve
```

### Other MCP Clients

Any client that supports stdio transport can connect. The server command is:

```bash
drush dkan-mcp:serve
```

(Prefix with `ddev` if running inside a DDEV environment.)

## Tools

### Metastore

| Tool | Parameters | Description |
|---|---|---|
| `list_datasets` | `offset?`, `limit?` | Dataset summaries with pagination |
| `get_dataset` | `identifier` | Full dataset metadata by UUID |
| `list_distributions` | `dataset_id` | Distributions for a dataset (includes `resource_id`) |
| `get_distribution` | `identifier` | Distribution metadata by UUID |
| `list_schemas` | — | Available metadata schema IDs |
| `get_catalog` | — | Full DCAT catalog |
| `get_dataset_info` | `uuid` | Aggregated lineage: distributions, resources, import status, perspectives |

### Datastore

| Tool | Parameters | Description |
|---|---|---|
| `query_datastore` | `resource_id`, `columns?`, `conditions?`, `sort_field?`, `sort_direction?`, `limit?`, `offset?` | Query with filters, sorting, pagination |
| `get_datastore_schema` | `resource_id` | Column names and types |
| `get_import_status` | `resource_id` | Import/processing status |

### Search

| Tool | Parameters | Description |
|---|---|---|
| `search_datasets` | `keyword`, `page?`, `page_size?` | Keyword search across datasets |

### Harvest

| Tool | Parameters | Description |
|---|---|---|
| `list_harvest_plans` | — | All registered harvest plan IDs |
| `get_harvest_plan` | `plan_id` | Plan config: source URL, extract/transform/load settings |
| `get_harvest_runs` | `plan_id` | All runs with timestamps and status |
| `get_harvest_run_result` | `plan_id`, `run_id?` | Detailed run result (latest if `run_id` omitted) |

### Introspection

| Tool | Parameters | Description |
|---|---|---|
| `list_services` | `module?` | DKAN service IDs with class names |
| `get_service_info` | `service_id` | Class, constructor dependencies, public method signatures |
| `list_events` | `module?` | Event constants with string values and declaring classes |
| `get_event_info` | `event_name` | Event details with registered subscriber classes and methods |
| `list_permissions` | `module?` | DKAN permissions with title, description, provider |
| `get_permission_info` | `permission` | Permission definition, routes requiring it, roles holding it |
| `check_permissions` | — | Detect permission misconfigurations (orphaned, unused) |
| `resolve_resource` | `id` | Trace distribution UUID or resource_id → perspectives → datastore table |

## Resource ID Formats

Metastore tools use **dataset/distribution UUIDs** (e.g., `b230fcde-aaf0-4cf5-a6f0-788fef498927`).

Datastore tools use **resource IDs** in the format `{identifier}__{version}` (e.g., `3a187a87dc6cd47c48b6b4c4785224b7__1773329007`). Get these from `list_distributions`, which returns a `resource_id` field extracted from the distribution's `%Ref:downloadURL` metadata.

## Architecture

- **Entry point**: `McpServeCommand` (Drush command) → `McpServerFactory` → `Mcp\Server` (stdio)
- **Tool classes**: `MetastoreTools`, `DatastoreTools`, `SearchTools`, `HarvestTools`, `ServiceTools`, `EventTools`, `PermissionTools`, `ResourceTools` — Drupal services with injected DKAN dependencies
- **opis/json-schema conflict**: DKAN requires opis v1, the MCP SDK requires v2. The SDK is installed in `dkan_mcp/vendor/` (not site-level). `SchemaValidatorShim` replaces the SDK's opis-dependent validator. The `post-install-cleanup` script removes opis packages from module vendor to prevent autoloader collisions.

## Development

### Running Tests

```bash
cd dkan_mcp && vendor/bin/phpunit
```

### Adding a Tool

1. Add a method to the appropriate tool class (`src/Tools/`)
2. Register it in `McpServerFactory::register*Tools()`
3. Add a test in `tests/src/Unit/`
