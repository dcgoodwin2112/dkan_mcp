# dkan_mcp Architecture

Technical documentation for how the DKAN MCP module works — from agent tool call to Drupal service execution.

## Stack

| Layer | Technology |
|---|---|
| Protocol | [Model Context Protocol](https://modelcontextprotocol.io/) (JSON-RPC 2.0) |
| MCP SDK | `mcp/sdk ^0.4` (PHP), a normal site-level Composer dependency |
| Server transports | `StdioTransport` (Drush, all 35 tools), `StreamableHttpTransport` (HTTP, 22 read-only tools) |
| Host framework | Drupal 11 + DKAN 4.x |
| Entry points | Drush command (`dkan-mcp:serve`), HTTP controller (`/mcp`) |
| Local dev | DDEV (provides the `ddev drush` wrapper) |

## Request Flow

### stdio transport (Drush)

```
MCP Client          McpServeCommand        MCP SDK Server       Tool Class          DKAN/Drupal
(local agent)       (Drush)                                     method()            Services
     |                    |                      |                    |                   |
     |--- JSON-RPC ------>|                      |                    |                   |
     |   (stdio)          |                      |                    |                   |
     |                    |-- creates ---------->|                    |                   |
     |                    |                      |-- invokes ------->|                   |
     |                    |                      | [Class,'method']   |                   |
     |                    |                      |                    |-- calls --------->|
     |                    |                      |                    |   injected svc    |
     |                    |                      |                    |<-- result --------|
     |                    |                      |<-- array ----------|                   |
     |<-- JSON-RPC -------|<-- response ---------|                    |                   |
     |   (stdio)          |                      |                    |                   |
```

### HTTP transport

```
MCP Client          McpController          MCP SDK Server       Tool Class          DKAN/Drupal
(remote agent)      (Drupal route)                              method()            Services
     |                    |                      |                    |                   |
     |--- POST /mcp ----->|                      |                    |                   |
     |   (JSON-RPC)       |-- PSR-7 bridge ----->|                    |                   |
     |                    |                      |-- invokes ------->|                   |
     |                    |                      | [Class,'method']   |                   |
     |                    |                      |                    |-- calls --------->|
     |                    |                      |                    |   injected svc    |
     |                    |                      |                    |<-- result --------|
     |                    |                      |<-- array ----------|                   |
     |<-- HTTP 200 -------|<-- PSR-7 response ---|                    |                   |
     |   (JSON-RPC)       |                      |                    |                   |
```

### Step by step (stdio)

1. **Client connects** — The MCP client reads `.mcp.json` and spawns `ddev drush dkan-mcp:serve` as a subprocess. All communication happens over stdin/stdout using JSON-RPC 2.0.

2. **Drush bootstraps Drupal** — Drush loads the full Drupal container, giving the command access to all DKAN and Drupal services via dependency injection.

3. **`McpServeCommand::serve()` starts the server** — Clears output buffering to protect the JSON-RPC stream, then builds the server.

4. **`McpServerFactory::create()` builds the server** — Uses `Server::builder()` to register tools from the declarative `TOOL_GROUPS` registry. Each tool is registered with a `[Class::class, 'method']` handler, name, description, input schema (explicit or auto-generated from the method), and annotations (`readOnlyHint`); handler instances resolve through a `ToolServiceContainer`. When called with no `$toolGroups` argument, all 35 tools are registered.

5. **Server enters run loop** — `$server->run(new StdioTransport())` reads JSON-RPC requests from stdin, dispatches them, and writes responses to stdout.

### Step by step (HTTP)

1. **Client sends POST** — A remote MCP client sends a JSON-RPC 2.0 request to `POST /mcp`. Drupal routes it to `McpController::handle()`.

2. **Controller bridges request** — Converts the Symfony `Request` to PSR-7 using `PsrHttpFactory`, and creates a `FileSessionStore` for cross-request session persistence.

3. **`McpServerFactory::create()` builds a subset server** — Called with `$toolGroups = ['metastore', 'datastore', 'search', 'harvest_read', 'resource', 'status']`, registering only 22 read-only tools.

4. **Server processes request** — `$server->run(new StreamableHttpTransport($psrRequest))` processes the JSON-RPC request and returns a PSR-7 `ResponseInterface`.

5. **Controller bridges response** — The PSR-7 response is converted back to a Symfony `Response` via `HttpFoundationFactory` and returned to Drupal.

### Common to both transports

6. **Tool call dispatch** — The SDK matches the tool name, validates parameters against the input schema, and invokes the registered handler (`[Class::class, 'method']`), resolving the instance through the factory's container.

7. **Tool class executes** — The SDK calls the bound method on the tool-class instance (e.g., `DatastoreTools::queryDatastore()`), mapping validated camelCase arguments to the method's parameters by name. The tool class validates/normalizes parameters, calls injected DKAN/Drupal services, and returns a structured array (or `['error' => $message]` on failure).

8. **Response returned** — The SDK serializes the result as a JSON-RPC response.

## Key Components

### McpServeCommand (`src/Drush/McpServeCommand.php`)

Drush command registered via `drush.services.yml` with the `drush.command` tag. Single dependency: `McpServerFactory`. Creates all 35 tools via `$this->serverFactory->create()` (no `$toolGroups` argument) and runs them over `StdioTransport`.

### McpController (`src/Controller/McpController.php`)

HTTP endpoint at `/mcp` registered via `dkan_mcp.routing.yml`. Bridges Symfony ↔ PSR-7 using `PsrHttpFactory` + `GuzzleHttp\Psr7\HttpFactory`. Creates a read-only subset of 22 tools via `$this->serverFactory->create(self::HTTP_TOOL_GROUPS, $sessionStore)`. Uses `FileSessionStore` for cross-request session persistence. Requires `access content` permission.

### McpCorsSubscriber (`src/EventSubscriber/McpCorsSubscriber.php`)

Event subscriber that adds CORS headers to all `/mcp` responses. Necessary because Drupal's `OptionsRequestSubscriber` intercepts OPTIONS requests before the controller runs, so the SDK transport's CORS headers are never set on preflight responses.

### McpServerFactory (`src/Server/McpServerFactory.php`)

Injected with all 7 tool service instances. Its `create(?array $toolGroups, ?SessionStoreInterface $sessionStore)` method builds the MCP `Server` from a declarative registry. When `$toolGroups` is `NULL`, all groups are registered (stdio default). When provided, only the listed groups are registered (HTTP subset). Tools are declared in the `self::TOOL_GROUPS` constant: a `group => [tool spec, ...]` map where each spec is `{name, class, method, readOnly, description, input?, output?}`. `create()` iterates the selected groups and registers each spec in one loop.

Tool registration is **declarative**: the handler is a direct `[Class::class, 'method']` callable, not a closure. The SDK reflects the method to **auto-generate the input schema** from its parameter types and `@param` docblocks — so the schema lives with the method, not duplicated in the factory. An explicit `input` schema is supplied only for the two complex datastore query tools (rich operator/expression detail) and `get_datastore_schema` (to hide its internal `includeDictionary` parameter). Tool descriptions are explicit in the spec (kept separate from internal developer docblocks); advisory `output` schemas are added for high-value structured returns.

```php
// In self::TOOL_GROUPS['datastore']:
[
  'name' => 'query_datastore',
  'class' => DatastoreTools::class,
  'method' => 'queryDatastore',
  'readOnly' => TRUE,
  'description' => 'Query a datastore resource table...',
  'input' => self::SCHEMA_QUERY_DATASTORE,
  'output' => self::OUT_QUERY_RESULT,
],
```

Because the SDK resolves array handlers to a *class name* and instantiates via a container, the factory passes a `ToolServiceContainer` (a minimal PSR-11 wrapper, `src/Server/ToolServiceContainer.php`) mapping each tool-class FQCN to the DI-built instance it received. Without it the SDK would `new Class()` and fail on the service-injected constructors.

Auto-generated schemas (and the explicit ones) use **camelCase** property names matching the method parameters, since the SDK derives property names from parameter names.

### Tool Classes

7 plain PHP classes, each registered as a Drupal service with constructor-injected dependencies. No base class or shared interface — each is a standalone adapter between MCP and DKAN/Drupal services.

The first three classes live in the **`dkan_query_tools`** module (shared with `dkan_drupal_ai_query`); the remainder live in `dkan_mcp/src/Tools/`.

| Service ID | Class | Source Module | DKAN/Drupal Dependencies |
|---|---|---|---|
| `dkan_query_tools.metastore` | `MetastoreTools` | dkan_query_tools | `MetastoreService`, `DatasetInfo` |
| `dkan_query_tools.datastore` | `DatastoreTools` | dkan_query_tools | `DatastoreService`, `Query`, `MetastoreService`, `DatasetInfo`, `database`, logger |
| `dkan_query_tools.search` | `SearchTools` | dkan_query_tools | `http_client`, `request_stack` |
| `dkan_mcp.tools.harvest` | `HarvestTools` | dkan_mcp | `HarvestService`, logger |
| `dkan_mcp.tools.resource` | `ResourceTools` | dkan_mcp | `MetastoreService`, `ResourceMapper`, `DatastoreService`, `DatasetInfo` |
| `dkan_mcp.tools.write` | `WriteTools` | dkan_mcp | `MetastoreService`, `DatastoreService`, logger |
| `dkan_mcp.tools.status` | `StatusTools` | dkan_mcp | `MetastoreService`, `DatasetInfo`, `HarvestService`, `module_handler`, `extension.list.module`, `queue`, `plugin.manager.queue_worker` |

### Tool class implementation pattern

Each method follows the same structure:

```php
public function queryDatastore(string $resourceId, ...): array {
  // 1. Validate/normalize parameters
  $limit = min(max($limit, 1), 500);

  // 2. Build DKAN-specific objects
  $query = new DatastoreQuery(json_encode([...]), $limit);

  // 3. Call DKAN service
  $result = $this->queryService->runQuery($query);

  // 4. Format and return
  return ['results' => ..., 'total_rows' => ...];
}
```

Error handling: all methods catch exceptions and return `['error' => $message]` instead of throwing. This prevents the MCP server process from crashing on individual tool failures.

## Client Configuration

### stdio (local development)

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

The `ddev` wrapper ensures the Drush command runs inside the DDEV container with full Drupal bootstrap, database access, and filesystem mounts. All 35 tools are available.

### HTTP (remote clients)

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

The HTTP endpoint exposes 22 read-only tools. Session management via `Mcp-Session-Id` header and `FileSessionStore`. Requires `access content` permission. CORS enabled for all origins.

## Testing

Unit tests run with the site-level PHPUnit and standalone stubs (no Drupal bootstrap):

```bash
cd docroot/modules/custom/dkan_mcp && ../../../../vendor/bin/phpunit
```

### Strategy

- **Unit tests** (`tests/src/Unit/Tools/*.php`) — one test class per tool class. Mock DKAN services using PHPUnit mocks, instantiate the tool class directly, call methods, assert return structure and values.

- **Stubs** (`tests/stubs/*.php`) — minimal implementations of DKAN classes (`MetastoreService`, `DatastoreService`, `RootedJsonData`, `HarvestService`, etc.) that satisfy autoloading without requiring the full DKAN codebase. Loaded via `tests/bootstrap.php`.

This design means tests verify tool logic (parameter validation, response formatting, error handling) without needing a running Drupal site or database.

## Design Decisions

**Thin adapters over business logic** — Tool classes contain no domain logic. They marshal parameters into DKAN query objects, delegate to DKAN services, and format responses. All data access patterns and business rules live in DKAN's service layer.

**Composition over inheritance** — No base class, no `ToolInterface`. Each tool class is a plain service with constructor injection. The factory composes them all.

**Resource ID bridging** — DKAN uses UUIDs for metastore entities and `{identifier}__{version}` hashes for datastore resources. `MetastoreTools::listDistributions()` returns both formats so agents can chain metastore lookups into datastore queries without manual ID translation.

**Read-only by default** — 23 of 35 tools are annotated `readOnlyHint: TRUE`. The 12 write tools (dataset/metastore lifecycle, imports, datastore drop, harvest write) are explicitly marked writable so MCP clients can enforce confirmation prompts.

**Transport-aware tool subsetting** — `McpServerFactory::create()` accepts a `$toolGroups` array, allowing each transport to expose a different tool set. The HTTP transport exposes only the read-only, data-consumer groups (metastore, datastore, search, harvest read, resource, status); all write tools are excluded from HTTP.

**Structured error returns** — Tool methods return `['error' => $message]` rather than throwing exceptions. This keeps the MCP server process alive and gives clients actionable error messages.

**Token-efficient responses** — Tools truncate descriptions, strip internal `%`-prefixed keys from DKAN JSON, and omit verbose fields to reduce token consumption in LLM contexts.
