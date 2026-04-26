# dkan_mcp Architecture

Technical documentation for how the DKAN MCP module works — from agent tool call to Drupal service execution.

## Stack

| Layer | Technology |
|---|---|
| Protocol | [Model Context Protocol](https://modelcontextprotocol.io/) (JSON-RPC 2.0) |
| MCP SDK | `mcp/sdk ^0.4` (PHP), installed in module-local `vendor/` |
| Server transports | `StdioTransport` (Drush, all 52 tools), `StreamableHttpTransport` (HTTP, 21 read-only tools) |
| Host framework | Drupal 10.6 + DKAN 2.22 |
| Entry points | Drush command (`dkan-mcp:serve`), HTTP controller (`/mcp`) |
| Local dev | DDEV (provides the `ddev drush` wrapper) |

## Request Flow

### stdio transport (Drush)

```
MCP Client          McpServeCommand        MCP SDK Server       Tool Class          DKAN/Drupal
(Claude Code)       (Drush)                                     method()            Services
     |                    |                      |                    |                   |
     |--- JSON-RPC ------>|                      |                    |                   |
     |   (stdio)          |                      |                    |                   |
     |                    |-- creates ---------->|                    |                   |
     |                    |                      |-- invokes ------->|                   |
     |                    |                      |   handler closure  |                   |
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
     |                    |                      |   handler closure  |                   |
     |                    |                      |                    |-- calls --------->|
     |                    |                      |                    |   injected svc    |
     |                    |                      |                    |<-- result --------|
     |                    |                      |<-- array ----------|                   |
     |<-- HTTP 200 -------|<-- PSR-7 response ---|                    |                   |
     |   (JSON-RPC)       |                      |                    |                   |
```

### Step by step (stdio)

1. **Client connects** — Claude Code reads `.mcp.json` and spawns `ddev drush dkan-mcp:serve` as a subprocess. All communication happens over stdin/stdout using JSON-RPC 2.0.

2. **Drush bootstraps Drupal** — Drush loads the full Drupal container, giving the command access to all DKAN and Drupal services via dependency injection.

3. **`McpServeCommand::serve()` starts the server** — Calls `McpAutoloaderTrait::loadMcpAutoloader()` to load the MCP SDK with namespace isolation (see [Autoloader Isolation](#autoloader-isolation)), then clears output buffering to protect the JSON-RPC stream.

4. **`McpServerFactory::create()` builds the server** — Uses `Server::builder()` to register tools. Each tool is registered with a handler closure, name, description, JSON Schema, and annotations (`readOnlyHint`). When called with no `$toolGroups` argument, all 52 tools are registered.

5. **Server enters run loop** — `$server->run(new StdioTransport())` reads JSON-RPC requests from stdin, dispatches them, and writes responses to stdout.

### Step by step (HTTP)

1. **Client sends POST** — A remote MCP client sends a JSON-RPC 2.0 request to `POST /mcp`. Drupal routes it to `McpController::handle()`.

2. **Controller bridges request** — The controller loads the MCP SDK autoloader (same trait as Drush), converts the Symfony `Request` to PSR-7 using `PsrHttpFactory`, and creates a `FileSessionStore` for cross-request session persistence.

3. **`McpServerFactory::create()` builds a subset server** — Called with `$toolGroups = ['metastore', 'datastore', 'search', 'harvest_read', 'resource', 'status']`, registering only 21 read-only tools.

4. **Server processes request** — `$server->run(new StreamableHttpTransport($psrRequest))` processes the JSON-RPC request and returns a PSR-7 `ResponseInterface`.

5. **Controller bridges response** — The PSR-7 response is converted back to a Symfony `Response` via `HttpFoundationFactory` and returned to Drupal.

### Common to both transports

6. **Tool call dispatch** — The SDK matches the tool name, validates parameters against the input schema, and invokes the registered handler closure.

7. **Tool class executes** — The handler closure calls the corresponding method on the tool class (e.g., `DatastoreTools::queryDatastore()`). The tool class validates/normalizes parameters, calls injected DKAN/Drupal services, and returns a structured array (or `['error' => $message]` on failure).

8. **Response returned** — The SDK serializes the result as a JSON-RPC response.

## Key Components

### McpAutoloaderTrait (`src/Server/McpAutoloaderTrait.php`)

Shared trait used by both `McpServeCommand` and `McpController`. Its `loadMcpAutoloader()` method loads the SchemaValidator shim, then loads and filters the module's vendor autoloader. See [Autoloader Isolation](#autoloader-isolation).

### McpServeCommand (`src/Drush/McpServeCommand.php`)

Drush command registered via `drush.services.yml` with the `drush.command` tag. Uses `McpAutoloaderTrait`. Single dependency: `McpServerFactory`. Creates all 52 tools via `$this->serverFactory->create()` (no `$toolGroups` argument).

### McpController (`src/Controller/McpController.php`)

HTTP endpoint at `/mcp` registered via `dkan_mcp.routing.yml`. Uses `McpAutoloaderTrait`. Bridges Symfony ↔ PSR-7 using `PsrHttpFactory` + `GuzzleHttp\Psr7\HttpFactory`. Creates a read-only subset of 21 tools via `$this->serverFactory->create(['metastore', 'datastore', ...], $sessionStore)`. Uses `FileSessionStore` for cross-request session persistence. Requires `access content` permission.

### McpCorsSubscriber (`src/EventSubscriber/McpCorsSubscriber.php`)

Event subscriber that adds CORS headers to all `/mcp` responses. Necessary because Drupal's `OptionsRequestSubscriber` intercepts OPTIONS requests before the controller runs, so the SDK transport's CORS headers are never set on preflight responses.

### McpServerFactory (`src/Server/McpServerFactory.php`)

Injected with all 12 tool service instances. Its `create(?array $toolGroups, ?SessionStoreInterface $sessionStore)` method builds the MCP `Server` by calling `register*Tools()` methods. When `$toolGroups` is `NULL`, all groups are registered (stdio default). When provided, only the listed groups are registered (HTTP subset). Tool groups are defined in `self::TOOL_GROUPS` constant.

Tool registration pattern:

```php
$builder->addTool(
  handler: fn(string $resource_id, int $limit = 100, ...)
    => $this->datastoreTools->queryDatastore($resource_id, $limit, ...),
  name: 'query_datastore',
  description: 'Query a datastore resource table...',
  annotations: new ToolAnnotations(readOnlyHint: TRUE),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resource_id' => ['type' => 'string', 'description' => '...'],
      'limit' => ['type' => 'integer', 'default' => 100],
    ],
    'required' => ['resource_id'],
  ],
);
```

The handler closures serve as the binding layer — they receive SDK-validated parameters and forward them to tool class methods with PHP type hints.

### Tool Classes

12 plain PHP classes, each registered as a Drupal service with constructor-injected dependencies. No base class or shared interface — each tool class is a standalone adapter between MCP and DKAN/Drupal services.

The first three classes live in the **`dkan_query_tools`** module (shared with `dkan_nl_query` and `dkan_drupal_ai_query`); the remainder live in `dkan_mcp/src/Tools/`.

| Service ID | Class | Source Module | DKAN/Drupal Dependencies |
|---|---|---|---|
| `dkan_query_tools.metastore` | `MetastoreTools` | dkan_query_tools | `MetastoreService`, `DatasetInfo` |
| `dkan_query_tools.datastore` | `DatastoreTools` | dkan_query_tools | `DatastoreService`, `Query`, `MetastoreService`, `DatasetInfo`, `database`, logger |
| `dkan_query_tools.search` | `SearchTools` | dkan_query_tools | `http_client`, `request_stack` |
| `dkan_mcp.tools.harvest` | `HarvestTools` | dkan_mcp | `HarvestService` |
| `dkan_mcp.tools.service` | `ServiceTools` | dkan_mcp | `service_container`, `module_handler` |
| `dkan_mcp.tools.event` | `EventTools` | dkan_mcp | `service_container`, `event_dispatcher` |
| `dkan_mcp.tools.permission` | `PermissionTools` | dkan_mcp | `user.permissions`, `router.route_provider`, `entity_type.manager`, `module_handler` |
| `dkan_mcp.tools.resource` | `ResourceTools` | dkan_mcp | `MetastoreService`, `ResourceMapper`, `DatastoreService`, `DatasetInfo` |
| `dkan_mcp.tools.write` | `WriteTools` | dkan_mcp | `module_installer`, `module_handler`, `MetastoreService`, `DatastoreService` |
| `dkan_mcp.tools.drupal` | `DrupalTools` | dkan_mcp | `entity_type.manager`, `entity_field.manager`, `entity_type.bundle.info`, `module_handler`, `extension.list.module`, `config.factory`, `router.route_provider`, `service_container` |
| `dkan_mcp.tools.status` | `StatusTools` | dkan_mcp | `MetastoreService`, `DatasetInfo`, `HarvestService`, `module_handler`, `extension.list.module` |
| `dkan_mcp.tools.log` | `LogTools` | dkan_mcp | `database` |

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

### SchemaValidatorShim (`src/Server/SchemaValidatorShim.php`)

A no-op replacement for the SDK's `Mcp\Capability\Discovery\SchemaValidator`. Loaded via `require_once` before the SDK autoloader so PHP resolves the class from the shim file rather than the SDK's version.

The shim's `validateAgainstJsonSchema()` always returns `[]` (no errors), effectively skipping JSON Schema validation. Tool inputs are still type-checked by the SDK's parameter casting and by PHP type hints on tool class methods.

## opis/json-schema Conflict

The MCP SDK depends on `opis/json-schema ^2`. DKAN depends on `opis/json-schema ^1`. Both versions use the same `Opis\JsonSchema` namespace, so PHP can only load one.

Resolution (two parts):

1. **Post-install cleanup** — `composer.json` defines a `post-install-cleanup` script that deletes `vendor/opis` from the module's vendor directory and rebuilds the autoloader. This prevents the module's v2 opis classes from colliding with DKAN's v1.

2. **SchemaValidator shim** — Since opis is removed, the SDK's `SchemaValidator` class (which imports opis) would fail. The shim replaces it with a no-op implementation.

## Autoloader Isolation

The module maintains its own `vendor/` directory (separate from the host site's `vendor/`) for the MCP SDK and its dependencies. However, many SDK transitive dependencies (Symfony, Doctrine, PSR packages) overlap with different major versions in Drupal's vendor.

`McpAutoloaderTrait::loadMcpAutoloader()` (shared by both entry points) solves this by filtering the module autoloader to only keep namespaces the SDK actually needs that the host doesn't provide:

```php
$keepPrefixes = [
  'Mcp\\',
  'Psr\\Clock\\',
  'Psr\\Http\\Server\\',
  'Psr\\SimpleCache\\',
  'Revolt\\',
  'Symfony\\Component\\Uid\\',
  'Symfony\\Polyfill\\Uuid\\',
  'phpDocumentor\\Reflection\\',
  'Http\\Discovery\\',
  'Webmozart\\Assert\\',
];
```

All other PSR-4 prefixes and classmap entries are stripped from the module's `ClassLoader`, letting Drupal's autoloader win for shared packages. `Psr\Http\Server\` is kept because the HTTP transport's `MiddlewareRequestHandler` requires `RequestHandlerInterface` and `MiddlewareInterface`, which are not in Drupal's site vendor.

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

The `ddev` wrapper ensures the Drush command runs inside the DDEV container with full Drupal bootstrap, database access, and filesystem mounts. All 52 tools are available.

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

The HTTP endpoint exposes 21 read-only tools. Session management via `Mcp-Session-Id` header and `FileSessionStore`. Requires `access content` permission. CORS enabled for all origins.

## Testing

Tests run standalone without Drupal bootstrap:

```bash
cd web/modules/custom/dkan_mcp && vendor/bin/phpunit
```

### Strategy

- **Unit tests** (`tests/src/Unit/Tools/*.php`) — one test class per tool class. Mock DKAN services using PHPUnit mocks, instantiate the tool class directly, call methods, assert return structure and values.

- **Stubs** (`tests/stubs/*.php`) — minimal implementations of DKAN classes (`MetastoreService`, `DatastoreService`, `RootedJsonData`, `HarvestService`, etc.) that satisfy autoloading without requiring the full DKAN codebase. Loaded via `tests/bootstrap.php`.

This design means tests verify tool logic (parameter validation, response formatting, error handling) without needing a running Drupal site or database.

## Design Decisions

**Thin adapters over business logic** — Tool classes contain no domain logic. They marshal parameters into DKAN query objects, delegate to DKAN services, and format responses. All data access patterns and business rules live in DKAN's service layer.

**Composition over inheritance** — No base class, no `ToolInterface`. Each tool class is a plain service with constructor injection. The factory composes them all.

**Resource ID bridging** — DKAN uses UUIDs for metastore entities and `{identifier}__{version}` hashes for datastore resources. `MetastoreTools::listDistributions()` returns both formats so agents can chain metastore lookups into datastore queries without manual ID translation.

**Read-only by default** — 38 of 52 tools are annotated `readOnlyHint: TRUE`. The 14 write tools are explicitly marked writable so MCP clients can enforce confirmation prompts.

**Transport-aware tool subsetting** — `McpServerFactory::create()` accepts a `$toolGroups` array, allowing each transport to expose a different tool set. The HTTP transport exposes only read-only, data-consumer tools (metastore, datastore, search, harvest read, resource, status). Dev/admin tools (services, events, permissions, Drupal introspection, logs) and all write tools are excluded from HTTP.

**Structured error returns** — Tool methods return `['error' => $message]` rather than throwing exceptions. This keeps the MCP server process alive and gives clients actionable error messages.

**Token-efficient responses** — Tools truncate descriptions (200 chars), strip internal `%`-prefixed keys from DKAN JSON, and omit verbose fields (`spatial`, etc.) to reduce token consumption in LLM contexts.
