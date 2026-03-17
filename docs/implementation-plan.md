> **Deprecated**: This is the original implementation plan for dkan_mcp, now historical. See [tool-suite-review.md](tool-suite-review.md) for the current state assessment.

# Plan: DKAN MCP Module

## Context

The `dkan_mcp` module will expose DKAN's data catalog and datastore to AI agents via the Model Context Protocol. This enables tools like Claude Code and Cursor to query datasets, inspect metadata, and search a DKAN site directly. The module is scaffolded but empty — this plan covers the full initial implementation.

**Key decisions:**
- Standalone module using `mcp/sdk` (official PHP SDK, v0.4, maintained by PHP Foundation + Symfony)
- No dependency on `drupal/mcp_server` contrib (alpha, stalled, 1 reported install)
- Read-only tools first, architected for future CRUD
- stdio transport via Drush command; HTTP deferred

## Architecture

### Transport & Entry Point

`ddev drush dkan-mcp:serve` starts an MCP server over stdin/stdout. The Drush command is a thin wrapper — it gets a configured `Server` from `McpServerFactory` and runs it on `StdioTransport`.

### Service Wiring

`McpServerFactory` is a Drupal service that builds the MCP `Server`, registering tool classes (also Drupal services with injected DKAN dependencies). This factory pattern lets a future HTTP controller share the same server setup.

### Tool Classes

Three classes grouped by domain. Each method is a single MCP tool with flat, LLM-friendly parameters.

| Tool | Class | DKAN Service | Description |
|---|---|---|---|
| `list_datasets` | MetastoreTools | MetastoreService | Dataset summaries with pagination |
| `get_dataset` | MetastoreTools | MetastoreService | Full dataset metadata by UUID |
| `list_distributions` | MetastoreTools | MetastoreService | Distributions for a dataset |
| `get_distribution` | MetastoreTools | MetastoreService | Distribution metadata by UUID |
| `list_schemas` | MetastoreTools | MetastoreService | Available schema IDs |
| `get_catalog` | MetastoreTools | MetastoreService | Full DCAT catalog |
| `query_datastore` | DatastoreTools | Query service | Query with filters, sorts, pagination |
| `get_datastore_schema` | DatastoreTools | DatastoreService | Column names/types for a resource |
| `get_import_status` | DatastoreTools | DatastoreService | Import status for a resource |
| `search_datasets` | SearchTools | HTTP client | Keyword search via internal `/api/1/search` |

**`query_datastore` parameter design:** Flat params (resource_id, columns as comma string, conditions as JSON string, sort_field, sort_direction, limit, offset). Tool constructs `DatastoreQuery` internally. Conditions use the same JSON format as the DKAN API: `[{"property":"state","value":"CA","operator":"="}]`.

## File Structure

```
dkan_mcp/
  composer.json                          # MODIFY: add mcp/sdk ^0.4
  dkan_mcp.info.yml                      # MODIFY: add metastore, datastore deps
  dkan_mcp.services.yml                  # MODIFY: tool classes + factory
  drush.services.yml                     # NEW: Drush command registration
  src/
    Drush/
      McpServeCommand.php                # NEW: `dkan-mcp:serve` Drush command
    Server/
      McpServerFactory.php               # NEW: builds MCP Server, registers tools
    Tools/
      MetastoreTools.php                 # NEW: 6 metastore tools
      DatastoreTools.php                 # NEW: 3 datastore tools
      SearchTools.php                    # NEW: 1 search tool
  tests/
    stubs/
      MetastoreService.php               # NEW
      DatastoreService.php               # NEW
      DatastoreQuery.php                 # NEW
      QueryService.php                   # NEW
      RootedJsonData.php                 # NEW
      DatabaseTableInterface.php         # NEW
    src/Unit/Tools/
      MetastoreToolsTest.php             # NEW
      DatastoreToolsTest.php             # NEW
```

## Key DKAN Interfaces

**`MetastoreService::getAll(string $schemaId)`** — returns array of objects
**`MetastoreService::get(string $schemaId, string $identifier)`** — returns `RootedJsonData`
**`MetastoreService::post(string $schemaId, RootedJsonData $data)`** — returns identifier string
**`Query::runQuery(DatastoreQuery $query)`** — returns object with `->results`, `->count`, `->schema`
**`DatastoreQuery::__construct(string $json, $rows_limit = null)`** — validates JSON against `docs/query.json` schema
**`DatastoreService::getStorage(string $id, ?string $version)`** — returns `DatabaseTableInterface`
**`DatastoreService::summary(string $identifier)`** — `$identifier` is full `identifier__version` resource ID; parses internally

## Service Configuration

**`dkan_mcp.services.yml`:**
```yaml
services:
  dkan_mcp.server_factory:
    class: Drupal\dkan_mcp\Server\McpServerFactory
    arguments: ['@dkan_mcp.tools.metastore', '@dkan_mcp.tools.datastore', '@dkan_mcp.tools.search']

  dkan_mcp.tools.metastore:
    class: Drupal\dkan_mcp\Tools\MetastoreTools
    arguments: ['@dkan.metastore.service']

  dkan_mcp.tools.datastore:
    class: Drupal\dkan_mcp\Tools\DatastoreTools
    arguments: ['@dkan.datastore.service', '@dkan.datastore.query']

  dkan_mcp.tools.search:
    class: Drupal\dkan_mcp\Tools\SearchTools
    arguments: ['@http_client', '@request_stack']
```

**`drush.services.yml`:**
```yaml
services:
  dkan_mcp.drush.mcp_serve:
    class: Drupal\dkan_mcp\Drush\McpServeCommand
    arguments: ['@dkan_mcp.server_factory']
    tags:
      - { name: drush.command }
```

## Risks & Mitigations

1. **SDK `addTool` callable format**: Docs show `[ClassName::class, 'method']` (static). We need `[$instance, 'method']` for DI'd objects. PHP callables support both forms, but the SDK may do its own resolution. **Mitigation:** Verify in Phase 1. Fallback: wrap in closures (`fn(...$args) => $this->tools->method(...$args)`) or use a static service locator.

2. **Drush stdout noise**: Drush may emit startup output that corrupts the JSON-RPC stream. **Mitigation:** Test early. Suppress Drush output before transport starts, or redirect to stderr.

3. **Search base URL in CLI**: `RequestStack` may lack a proper host in Drush context. **Mitigation:** DDEV sets `DRUSH_OPTIONS_URI`. Fallback to `http://localhost` or site settings.

## Implementation Phases

### Phase 1 — Core scaffold + smoke test
1. `composer.json`: add `mcp/sdk` dependency
2. `dkan_mcp.info.yml`: add `dkan:metastore`, `dkan:datastore` dependencies
3. Create `McpServeCommand` (Drush command)
4. Create `McpServerFactory` with manual tool registration
5. Create `MetastoreTools` with `list_datasets` + `get_dataset` only
6. Wire services in `dkan_mcp.services.yml` + `drush.services.yml`
7. **Verify:** `ddev drush dkan-mcp:serve` starts without error, can list tools

### Phase 2 — Complete read tools
8. Remaining `MetastoreTools` methods (list_distributions, get_distribution, list_schemas, get_catalog)
9. `DatastoreTools` — query_datastore, get_datastore_schema, get_import_status
10. `SearchTools` — search_datasets
11. Register all in `McpServerFactory`

### Phase 3 — Testing
12. Test stubs in `tests/stubs/`
13. `MetastoreToolsTest` — mock MetastoreService, verify return shapes
14. `DatastoreToolsTest` — mock services, verify query construction

### Phase 4 — Polish
15. Error handling (try/catch in tools, structured error responses)
16. Tool descriptions via docblocks/attributes for LLM discoverability
17. Update CLAUDE.md with dkan_mcp architecture section

## Verification

```bash
# Install SDK
cd dkan_mcp && composer require mcp/sdk:^0.4

# Enable module
ddev drush en dkan_mcp

# Start MCP server (should block, waiting for JSON-RPC on stdin)
ddev drush dkan-mcp:serve

# Configure Claude Code MCP client:
# "dkan": { "command": "ddev", "args": ["drush", "dkan-mcp:serve"] }

# Run tests
cd dkan_mcp && phpunit
```
