# dkan_mcp Architecture

Technical documentation for how the DKAN MCP module works — from agent tool call to Drupal service execution.

## Stack

| Layer | Technology |
|---|---|
| Protocol | [Model Context Protocol](https://modelcontextprotocol.io/) (JSON-RPC 2.0 over stdio) |
| MCP SDK | `mcp/sdk ^0.4` (PHP), installed in module-local `vendor/` |
| Server transport | `Mcp\Server\Transport\StdioTransport` |
| Host framework | Drupal 10.6 + DKAN 2.22 |
| Entry point | Drush command (`dkan-mcp:serve`) |
| Local dev | DDEV (provides the `ddev drush` wrapper) |

## Request Flow

```
MCP Client          McpServeCommand        MCP SDK Server       Tool Class          DKAN/Drupal
(Claude Code)       (Drush)                                     method()            Services
     |                    |                      |                    |                   |
     |--- JSON-RPC ------>|                      |                    |                   |
     |   (stdio)          |                      |                    |                   |
     |                    |-- creates ---------->|                    |                   |
     |                    |                      |                    |                   |
     |                    |                      |-- invokes ------->|                   |
     |                    |                      |   handler closure  |                   |
     |                    |                      |                    |-- calls --------->|
     |                    |                      |                    |   injected svc    |
     |                    |                      |                    |<-- result --------|
     |                    |                      |<-- array ----------|                   |
     |<-- JSON-RPC -------|<-- response ---------|                    |                   |
     |   (stdio)          |                      |                    |                   |
```

### Step by step

1. **Client connects** — Claude Code reads `.mcp.json` and spawns `ddev drush dkan-mcp:serve` as a subprocess. All communication happens over stdin/stdout using JSON-RPC 2.0.

2. **Drush bootstraps Drupal** — Drush loads the full Drupal container, giving the command access to all DKAN and Drupal services via dependency injection.

3. **`McpServeCommand::serve()` starts the server** — Three things happen before the MCP server runs:
   - **SchemaValidator shim** is loaded (see [opis conflict](#opisjson-schema-conflict) below)
   - **Module vendor autoloader** is filtered to prevent namespace collisions with the host site
   - **Output buffering** is cleared to protect the JSON-RPC stream from stray Drupal output

4. **`McpServerFactory::create()` builds the server** — Uses `Server::builder()` to register all 36 tools. Each tool is registered with:
   - A **handler** — a closure that forwards arguments to a tool class method
   - A **name**, **description**, and **JSON Schema** for input validation
   - **Annotations** — `readOnlyHint: TRUE` (31 tools) or `FALSE` (5 write tools)

5. **Server enters run loop** — `$server->run(new StdioTransport())` reads JSON-RPC requests from stdin, dispatches them, and writes responses to stdout.

6. **Tool call dispatch** — When the SDK receives a `tools/call` request, it matches the tool name, validates parameters against the input schema, and invokes the registered handler closure.

7. **Tool class executes** — The handler closure calls the corresponding method on the tool class (e.g., `DatastoreTools::queryDatastore()`). The tool class:
   - Validates and normalizes parameters
   - Calls injected DKAN/Drupal services
   - Returns a structured array (or `['error' => $message]` on failure)

8. **Response returned** — The SDK serializes the array as a JSON-RPC response and writes it to stdout. The client receives it.

## Key Components

### McpServeCommand (`src/Drush/McpServeCommand.php`)

Drush command registered via `drush.services.yml` with the `drush.command` tag. Single dependency: `McpServerFactory`. The `serve()` method handles the autoloader setup and starts the server.

```yaml
# drush.services.yml
services:
  dkan_mcp.drush.mcp_serve:
    class: Drupal\dkan_mcp\Drush\McpServeCommand
    arguments: ['@dkan_mcp.server_factory']
    tags: [{ name: drush.command }]
```

### McpServerFactory (`src/Server/McpServerFactory.php`)

Injected with all 11 tool service instances. Its `create()` method builds the MCP `Server` by calling 11 `register*Tools()` methods, each registering tools via `$builder->addTool()`.

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

### Tool Classes (`src/Tools/*.php`)

11 plain PHP classes, each registered as a Drupal service with constructor-injected dependencies. No base class or shared interface — each tool class is a standalone adapter between MCP and DKAN/Drupal services.

| Service ID | Class | DKAN/Drupal Dependencies |
|---|---|---|
| `dkan_mcp.tools.metastore` | `MetastoreTools` | `MetastoreService`, `DatasetInfo` |
| `dkan_mcp.tools.datastore` | `DatastoreTools` | `DatastoreService`, `Query` |
| `dkan_mcp.tools.search` | `SearchTools` | `http_client`, `request_stack` |
| `dkan_mcp.tools.harvest` | `HarvestTools` | `HarvestService` |
| `dkan_mcp.tools.service` | `ServiceTools` | `service_container`, `module_handler` |
| `dkan_mcp.tools.event` | `EventTools` | `service_container`, `event_dispatcher` |
| `dkan_mcp.tools.permission` | `PermissionTools` | `user.permissions`, `router.route_provider`, `entity_type.manager`, `module_handler` |
| `dkan_mcp.tools.resource` | `ResourceTools` | `MetastoreService`, `ResourceMapper`, `DatastoreService`, `DatasetInfo` |
| `dkan_mcp.tools.write` | `WriteTools` | `module_installer`, `module_handler`, `MetastoreService`, `DatastoreService` |
| `dkan_mcp.tools.drupal` | `DrupalTools` | `entity_type.manager`, `entity_field.manager`, `entity_type.bundle.info`, `module_handler`, `extension.list.module`, `config.factory`, `router.route_provider`, `service_container` |
| `dkan_mcp.tools.status` | `StatusTools` | `MetastoreService`, `DatasetInfo`, `HarvestService`, `module_handler`, `extension.list.module` |

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

`McpServeCommand::serve()` solves this by filtering the module autoloader to only keep namespaces the SDK actually needs that the host doesn't provide:

```php
$keepPrefixes = [
  'Mcp\\',
  'Psr\\Clock\\',
  'Psr\\SimpleCache\\',
  'Revolt\\',
  'Symfony\\Component\\Uid\\',
  'Symfony\\Polyfill\\Uuid\\',
  'phpDocumentor\\Reflection\\',
  'Http\\Discovery\\',
  'Webmozart\\Assert\\',
];
```

All other PSR-4 prefixes and classmap entries are stripped from the module's `ClassLoader`, letting Drupal's autoloader win for shared packages.

## Client Configuration

Claude Code discovers the MCP server via `.mcp.json` in the project root:

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

Alternative CLI setup: `claude mcp add --transport stdio dkan --scope project -- ddev drush dkan-mcp:serve`

The `ddev` wrapper ensures the Drush command runs inside the DDEV container with full Drupal bootstrap, database access, and filesystem mounts.

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

**Read-only by default** — 31 of 36 tools are annotated `readOnlyHint: TRUE`. The 5 write tools (`clear_cache`, `enable_module`, `disable_module`, `create_test_dataset`, `import_resource`) are explicitly marked writable so MCP clients can enforce confirmation prompts.

**Structured error returns** — Tool methods return `['error' => $message]` rather than throwing exceptions. This keeps the MCP server process alive and gives clients actionable error messages.

**Token-efficient responses** — Tools truncate descriptions (200 chars), strip internal `%`-prefixed keys from DKAN JSON, and omit verbose fields (`spatial`, etc.) to reduce token consumption in LLM contexts.
