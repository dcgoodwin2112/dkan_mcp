# Task: Optimize dkan_mcp Tool Response Sizes for Token Efficiency

## Context

The `dkan_mcp` module at `web/modules/custom/dkan_mcp/` exposes DKAN's internals to AI agents via MCP. In experiments comparing agents with MCP tools vs agents reading source code, **MCP was 2x faster but consumed identical tokens** (~3.3M each).

The root cause: MCP tool responses are verbose — returning all methods, all services, all events when the agent only needs a subset. These responses stay in the conversation history and are resent on every subsequent turn, compounding the cost. Reducing response sizes saves tokens proportional to `chars_saved × remaining_turns`.

## What to Change

Modify three tool classes and their registrations in McpServerFactory. Changes are additive (new optional parameters with backwards-compatible defaults). All existing tests must continue to pass.

### 1. Add `methods` filter to `get_service_info` and `get_class_info` (ServiceTools.php)

**Problem:** `get_service_info(dkan.metastore.service)` returns all 19 public methods (3,083 chars) when the agent typically needs 2-3. `get_class_info(DataResource)` returns 21 methods (2,885 chars) when the agent only needs `getIdentifier()`.

**Solution:** Add an optional `methods` string parameter — a comma-separated list of method name patterns. When provided, filter the `methods` array to only include matches. Support exact names and glob-style `*` wildcards.

```php
// ServiceTools::getServiceInfo
public function getServiceInfo(string $serviceId, ?string $methods = NULL, bool $includeYaml = TRUE): array

// ServiceTools::getClassInfo
public function getClassInfo(string $className, ?string $methods = NULL): array
```

Filter logic (apply to both):
```php
if ($methods !== NULL) {
    $patterns = array_map('trim', explode(',', $methods));
    $filteredMethods = array_filter($allMethods, function ($m) use ($patterns) {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $m['name'])) {
                return TRUE;
            }
        }
        return FALSE;
    });
    $allMethods = array_values($filteredMethods);
}
```

Update `McpServerFactory::registerServiceTools` inputSchema to include the new parameters:
- `methods`: `{"type": "string", "description": "Comma-separated method names or patterns (e.g. 'get*,count'). Omit for all methods."}`
- `include_yaml`: `{"type": "boolean", "description": "Include YAML service definition. Defaults to true."}` (for `get_service_info` only)

### 2. Add `include_yaml` toggle to `get_service_info` (ServiceTools.php)

**Problem:** The `yaml_definition` field (arguments, tags, calls) is always included, adding 200-500 chars per call. It's useful when writing `*.services.yml` but unnecessary when just discovering method signatures.

**Solution:** Already shown above — the `includeYaml` parameter defaults to `TRUE` for backwards compatibility. When `FALSE`, skip the `findServiceYamlDefinition` call entirely.

```php
if ($includeYaml) {
    $yamlDef = $this->findServiceYamlDefinition($serviceId);
    if ($yamlDef) {
        $result['yaml_definition'] = $yamlDef;
    }
}
```

### 3. Add `fields` filter to `get_event_info` (EventTools.php)

**Problem:** `get_event_info` returns constant, class, module, all subscribers, event class, all event methods, and dispatch payload with its methods — everything. The agent often just needs the constant and payload type.

**Solution:** Add an optional `fields` string parameter — a comma-separated list of top-level fields to include.

```php
public function getEventInfo(string $eventName, ?string $fields = NULL): array
```

When `fields` is provided, after building the full result, filter it:
```php
if ($fields !== NULL) {
    $allowed = array_map('trim', explode(',', $fields));
    $match = array_intersect_key($match, array_flip($allowed));
}
```

Update `McpServerFactory::registerEventTools` inputSchema:
- `fields`: `{"type": "string", "description": "Comma-separated field names to include (e.g. 'constant,event_class,dispatch_payload'). Omit for all fields."}`

### 4. Add `brief` mode to `list_services` and `list_events` (ServiceTools.php, EventTools.php)

**Problem:** `list_services(metastore)` returns 19 objects with `{id, class}` (1,823 chars). `list_events()` returns 15 objects with `{constant, event_name, declaring_class, module}` (2,487 chars). Often the agent just needs the IDs/names.

**Solution:** Add an optional `brief` boolean parameter (default `FALSE` for backwards compatibility). When `TRUE`, return only identifiers.

`ServiceTools::listServices`:
```php
public function listServices(?string $module = NULL, bool $brief = FALSE): array
```
When `brief`, return `['services' => $ids, 'total' => count($ids)]` — just the string array of service IDs.

`EventTools::listEvents`:
```php
public function listEvents(?string $module = NULL, bool $brief = FALSE): array
```
When `brief`, return `['events' => array_column($events, 'event_name'), 'total' => count($events)]` — just the string array of event names.

Update both McpServerFactory registrations:
- `brief`: `{"type": "boolean", "description": "Return only IDs/names without class details. Defaults to false."}`

### 5. Add composite `discover_api` tool (ServiceTools.php + McpServerFactory)

**Problem:** Discovering how to call a service method requires 2-3 sequential tool calls: `list_services` → `get_service_info` → `get_class_info` (follow return type). Each call is a conversation turn that resends the full context.

**Solution:** Add a new `discoverApi` method to `ServiceTools` and register it as `discover_api` in `McpServerFactory::registerServiceTools`.

```php
public function discoverApi(string $serviceId, ?string $method = NULL, int $depth = 1): array
```

Parameters:
- `serviceId`: The service to inspect
- `method`: Optional specific method name. If provided, only return info for that method and follow its return type.
- `depth`: How many levels of return types to follow (default 1). `0` = service info only. `1` = service + return type's public methods. `2` = follow one more level.

Logic:
1. Call `getServiceInfo($serviceId, $method, false)` (no YAML since this is for discovery)
2. If `$method` is provided and `$depth > 0`, find the method's return type
3. If the return type is a class/interface, call `getClassInfo($returnType)` to get its methods
4. If `$depth > 1`, recurse one more level on return types of those methods
5. Return a nested structure:

```json
{
  "service_id": "dkan.datastore.service",
  "class": "Drupal\\datastore\\DatastoreService",
  "method": {
    "name": "getStorage",
    "params": [{"name": "identifier", "type": "string"}, {"name": "version", "type": "string"}],
    "return_type": "Drupal\\datastore\\Storage\\DatabaseTable",
    "return_type_methods": [
      {"name": "getSchema", "params": [], "return_type": "array"},
      {"name": "count", "params": [], "return_type": "int"},
      {"name": "query", "params": [{"name": "query", "type": "Drupal\\common\\Storage\\Query"}], "return_type": "Drupal\\common\\Storage\\QueryResult"}
    ]
  }
}
```

McpServerFactory registration:
```php
$builder->addTool(
    handler: fn(string $service_id, ?string $method = NULL, int $depth = 1) =>
        $this->serviceTools->discoverApi($service_id, $method, $depth),
    name: 'discover_api',
    description: 'Get a service method signature and follow its return type to show available methods on returned objects. Collapses multiple get_service_info/get_class_info calls into one.',
    annotations: $readOnly,
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'service_id' => [
                'type' => 'string',
                'description' => 'Service ID (e.g. dkan.datastore.service).',
            ],
            'method' => [
                'type' => 'string',
                'description' => 'Method name to inspect. Omit for all methods (no return type following).',
            ],
            'depth' => [
                'type' => 'integer',
                'description' => 'Levels of return types to follow. 0=service only, 1=follow one return type (default), 2=two levels.',
            ],
        ],
        'required' => ['service_id'],
    ],
);
```

## Testing

- All existing unit tests in `tests/src/Unit/Tools/ServiceToolsTest.php` and `EventToolsTest.php` must still pass (backwards compatibility — new params have defaults)
- Add tests for each new parameter:
  - `testGetServiceInfoMethodsFilter` — verify only matching methods returned
  - `testGetServiceInfoExcludeYaml` — verify yaml_definition absent
  - `testGetClassInfoMethodsFilter` — verify filtering works
  - `testListServicesBrief` — verify returns string array
  - `testListEventsBrief` — verify returns string array
  - `testGetEventInfoFields` — verify only requested fields returned
  - `testDiscoverApi` — verify return type following works
  - `testDiscoverApiWithMethod` — verify single method + return type info
- Run `cd dkan_mcp && vendor/bin/phpunit` to verify

## Files to Modify

- `src/Tools/ServiceTools.php` — add parameters to `listServices`, `getServiceInfo`, `getClassInfo`; add `discoverApi` method
- `src/Tools/EventTools.php` — add parameters to `listEvents`, `getEventInfo`
- `src/Server/McpServerFactory.php` — update inputSchema for 5 existing tools; register new `discover_api` tool
- `tests/src/Unit/Tools/ServiceToolsTest.php` — add tests for new parameters
- `tests/src/Unit/Tools/EventToolsTest.php` — add tests for new parameters

## Docs to Update

After implementation, update `CLAUDE.md` in the dkan_mcp module:
- Document the new optional parameters in the "Service Discovery" and "Event-Driven Extension" workflow sections
- Add `discover_api` to the "Service Discovery → Dependency Injection" workflow
- Add note about using `brief: true` and `methods` filter for token efficiency
