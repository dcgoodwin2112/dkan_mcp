<?php

namespace Drupal\dkan_mcp\Server;

use Drupal\dkan_mcp\Tools\DatastoreTools;
use Drupal\dkan_mcp\Tools\EventTools;
use Drupal\dkan_mcp\Tools\HarvestTools;
use Drupal\dkan_mcp\Tools\PermissionTools;
use Drupal\dkan_mcp\Tools\MetastoreTools;
use Drupal\dkan_mcp\Tools\ResourceTools;
use Drupal\dkan_mcp\Tools\SearchTools;
use Drupal\dkan_mcp\Tools\ServiceTools;
use Drupal\dkan_mcp\Tools\DrupalTools;
use Drupal\dkan_mcp\Tools\WriteTools;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;

/**
 * Builds a configured MCP Server with all DKAN tools registered.
 */
class McpServerFactory {

  public function __construct(
    protected MetastoreTools $metastoreTools,
    protected DatastoreTools $datastoreTools,
    protected SearchTools $searchTools,
    protected HarvestTools $harvestTools,
    protected ServiceTools $serviceTools,
    protected EventTools $eventTools,
    protected PermissionTools $permissionTools,
    protected ResourceTools $resourceTools,
    protected WriteTools $writeTools,
    protected DrupalTools $drupalTools,
  ) {}

  public function create(): Server {
    $builder = Server::builder()
      ->setServerInfo('dkan', '1.0.0');

    $this->registerMetastoreTools($builder);
    $this->registerDatastoreTools($builder);
    $this->registerSearchTools($builder);
    $this->registerHarvestTools($builder);
    $this->registerServiceTools($builder);
    $this->registerEventTools($builder);
    $this->registerPermissionTools($builder);
    $this->registerResourceTools($builder);
    $this->registerWriteTools($builder);
    $this->registerDrupalTools($builder);

    return $builder->build();
  }

  protected function registerMetastoreTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(int $offset = 0, int $limit = 25) => $this->metastoreTools->listDatasets($offset, $limit),
      name: 'list_datasets',
      description: 'List dataset summaries with pagination. Returns title, identifier, description, and distribution count for each dataset.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'offset' => ['type' => 'integer', 'description' => 'Number of datasets to skip', 'default' => 0],
          'limit' => ['type' => 'integer', 'description' => 'Max datasets to return (1-100)', 'default' => 25],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier) => $this->metastoreTools->getDataset($identifier),
      name: 'get_dataset',
      description: 'Get full metadata for a dataset by its UUID. Returns the complete DCAT dataset object including title, description, distributions, keywords, and all other metadata fields.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Dataset UUID'],
        ],
        'required' => ['identifier'],
      ],
    );

    $builder->addTool(
      handler: fn(string $dataset_id) => $this->metastoreTools->listDistributions($dataset_id),
      name: 'list_distributions',
      description: 'List distributions (data files) for a dataset. Returns download URL, media type, and title for each distribution.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'dataset_id' => ['type' => 'string', 'description' => 'Dataset UUID'],
        ],
        'required' => ['dataset_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier) => $this->metastoreTools->getDistribution($identifier),
      name: 'get_distribution',
      description: 'Get full metadata for a distribution by its UUID.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Distribution UUID'],
        ],
        'required' => ['identifier'],
      ],
    );

    $builder->addTool(
      handler: fn() => $this->metastoreTools->listSchemas(),
      name: 'list_schemas',
      description: 'List available metadata schema IDs (e.g. dataset, distribution, keyword, theme).',
      annotations: $readOnly,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );

    $builder->addTool(
      handler: fn() => $this->metastoreTools->getCatalog(),
      name: 'get_catalog',
      description: 'Get the full DCAT data catalog with all datasets and their metadata.',
      annotations: $readOnly,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );

    $builder->addTool(
      handler: fn(string $uuid) => $this->metastoreTools->getDatasetInfo($uuid),
      name: 'get_dataset_info',
      description: 'Get aggregated dataset info including all distribution details. Returns latest_revision.distributions[] with keys: distribution_uuid, resource_id, resource_version, mime_type, source_path, importer_status ("waiting"|"done"|"error"), importer_percent_done, importer_error, table_name, fetcher_status, fetcher_percent_done, file_path. Use this to discover the actual data structure of DatasetInfo::gather().',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'uuid' => ['type' => 'string', 'description' => 'Dataset UUID'],
        ],
        'required' => ['uuid'],
      ],
    );
  }

  protected function registerDatastoreTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(
        string $resource_id,
        ?string $columns = NULL,
        ?string $conditions = NULL,
        ?string $sort_field = NULL,
        string $sort_direction = 'asc',
        int $limit = 100,
        int $offset = 0,
      ) => $this->datastoreTools->queryDatastore(
        $resource_id, $columns, $conditions, $sort_field, $sort_direction, $limit, $offset,
      ),
      name: 'query_datastore',
      description: 'Query a datastore resource table with optional filters, sorting, and pagination. Use get_datastore_schema first to discover available columns.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => [
            'type' => 'string',
            'description' => 'Resource ID in identifier__version format (from list_distributions)',
          ],
          'columns' => ['type' => 'string', 'description' => 'Comma-separated column names to return (omit for all)'],
          'conditions' => ['type' => 'string', 'description' => 'JSON array of condition objects: [{"property":"col","value":"val","operator":"="}]. Operators: =, <>, <, <=, >, >=, like, contains, starts with, in, not in, between'],
          'sort_field' => ['type' => 'string', 'description' => 'Column name to sort by'],
          'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
          'limit' => ['type' => 'integer', 'description' => 'Max rows to return (1-500)', 'default' => 100],
          'offset' => ['type' => 'integer', 'description' => 'Number of rows to skip', 'default' => 0],
        ],
        'required' => ['resource_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $resource_id) => $this->datastoreTools->getDatastoreSchema($resource_id),
      name: 'get_datastore_schema',
      description: 'Get column names and types for a datastore resource. Use this before querying to discover available fields.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => [
            'type' => 'string',
            'description' => 'Resource ID in identifier__version format (from list_distributions)',
          ],
        ],
        'required' => ['resource_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $resource_id) => $this->datastoreTools->getImportStatus($resource_id),
      name: 'get_import_status',
      description: 'Get the import/processing status of a datastore resource.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => [
            'type' => 'string',
            'description' => 'Resource ID in identifier__version format (from list_distributions)',
          ],
        ],
        'required' => ['resource_id'],
      ],
    );
  }

  protected function registerSearchTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(string $keyword, int $page = 1, int $page_size = 10) => $this->searchTools->searchDatasets($keyword, $page, $page_size),
      name: 'search_datasets',
      description: 'Search datasets by keyword. Returns matching datasets with title, identifier, description, and relevance.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'keyword' => ['type' => 'string', 'description' => 'Search term'],
          'page' => ['type' => 'integer', 'description' => 'Page number (1-based)', 'default' => 1],
          'page_size' => ['type' => 'integer', 'description' => 'Results per page', 'default' => 10],
        ],
        'required' => ['keyword'],
      ],
    );
  }

  protected function registerHarvestTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn() => $this->harvestTools->listHarvestPlans(),
      name: 'list_harvest_plans',
      description: 'List all registered harvest plan IDs.',
      annotations: $readOnly,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );

    $builder->addTool(
      handler: fn(string $plan_id) => $this->harvestTools->getHarvestPlan($plan_id),
      name: 'get_harvest_plan',
      description: 'Get harvest plan configuration: source URL, extract/transform/load settings.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'plan_id' => ['type' => 'string', 'description' => 'Harvest plan ID'],
        ],
        'required' => ['plan_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $plan_id) => $this->harvestTools->getHarvestRuns($plan_id),
      name: 'get_harvest_runs',
      description: 'List all runs for a harvest plan with timestamps and status.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'plan_id' => ['type' => 'string', 'description' => 'Harvest plan ID'],
        ],
        'required' => ['plan_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $plan_id, ?string $run_id = NULL) => $this->harvestTools->getHarvestRunResult($plan_id, $run_id),
      name: 'get_harvest_run_result',
      description: 'Detailed result for a harvest run: created/updated/failed datasets. Returns latest run if no run_id specified.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'plan_id' => ['type' => 'string', 'description' => 'Harvest plan ID'],
          'run_id' => ['type' => 'string', 'description' => 'Run ID/timestamp (omit for latest)'],
        ],
        'required' => ['plan_id'],
      ],
    );
  }

  protected function registerEventTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $module = NULL) => $this->eventTools->listEvents($module),
      name: 'list_events',
      description: 'List DKAN events with constant names, string values, and declaring classes. Filter by module (metastore, datastore, common).',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module' => ['type' => 'string', 'description' => 'Module name to filter (e.g. metastore, datastore). Omit for all DKAN events.'],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $event_name) => $this->eventTools->getEventInfo($event_name),
      name: 'get_event_info',
      description: 'Get event details: declaring class, constant name, module, subscribers, event class (from subscriber type hints), event class methods, and dispatch payload type (the actual object passed to getData() at the dispatch site). For events using Drupal\common\Events\Event, the dispatch_payload field shows what getData() returns.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'event_name' => ['type' => 'string', 'description' => 'Event name string (e.g. dkan_metastore_dataset_update)'],
        ],
        'required' => ['event_name'],
      ],
    );
  }

  protected function registerResourceTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(string $id) => $this->resourceTools->resolveResource($id),
      name: 'resolve_resource',
      description: 'Trace the full reference chain for a resource: distribution UUID or resource_id (identifier__version) → perspectives (source, local_file, local_url) → datastore table name, import status, and dataset_uuid (reverse lookup to the owning dataset).',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'id' => ['type' => 'string', 'description' => 'Distribution UUID or resource_id in identifier__version format'],
        ],
        'required' => ['id'],
      ],
    );
  }

  protected function registerPermissionTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $module = NULL) => $this->permissionTools->listPermissions($module),
      name: 'list_permissions',
      description: 'List DKAN permissions with metadata (title, description, provider module). Filter by module name.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module' => ['type' => 'string', 'description' => 'Module name to filter (e.g. harvest, datastore, metastore). Omit for all DKAN permissions.'],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $permission) => $this->permissionTools->getPermissionInfo($permission),
      name: 'get_permission_info',
      description: 'Get details for a permission: definition, routes that require it, and roles that have it.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'permission' => ['type' => 'string', 'description' => 'Permission machine name (e.g. harvest_api_index)'],
        ],
        'required' => ['permission'],
      ],
    );

    $builder->addTool(
      handler: fn() => $this->permissionTools->checkPermissions(),
      name: 'check_permissions',
      description: 'Detect DKAN permission misconfigurations: permissions in routes but not defined, defined but unused, or assigned to roles but not defined.',
      annotations: $readOnly,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );
  }

  protected function registerServiceTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $module = NULL) => $this->serviceTools->listServices($module),
      name: 'list_services',
      description: 'List DKAN service IDs with class names. Filter by module (metastore, datastore, harvest, common).',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module' => ['type' => 'string', 'description' => 'Module name to filter (e.g. metastore, datastore). Omit for all DKAN services.'],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $service_id) => $this->serviceTools->getServiceInfo($service_id),
      name: 'get_service_info',
      description: 'Get service details: class name, constructor dependencies, public method signatures, and YAML definition (arguments, calls/setter injection, tags). Shows constructor params only — use get_class_info on the service class to find setter methods from calls: entries.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'service_id' => ['type' => 'string', 'description' => 'Drupal service ID (e.g. dkan.metastore.service)'],
        ],
        'required' => ['service_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $class_name) => $this->serviceTools->getClassInfo($class_name),
      name: 'get_class_info',
      description: 'Get the full public API of any PHP class or interface: parent class, interfaces, and all public methods with parameter types, return types, and declaring class. Use to follow return types from get_service_info.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'class_name' => [
            'type' => 'string',
            'description' => 'Fully-qualified PHP class or interface name (e.g., Drupal\\datastore\\Storage\\DatabaseTable)',
          ],
        ],
        'required' => ['class_name'],
      ],
    );
  }

  protected function registerWriteTools(Server\Builder $builder): void {
    $write = new ToolAnnotations(readOnlyHint: FALSE);

    $builder->addTool(
      handler: fn() => $this->writeTools->clearCache(),
      name: 'clear_cache',
      description: 'Flush all Drupal caches. Use after code changes, config updates, or when cached data is stale. Does not rebuild the service container — restart the MCP server after services.yml changes.',
      annotations: $write,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );

    $builder->addTool(
      handler: fn(string $module_name) => $this->writeTools->enableModule($module_name),
      name: 'enable_module',
      description: 'Enable a Drupal module. Installs the module and runs its install hooks. Restart the MCP server afterward if the module registers new services or routes.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module_name' => ['type' => 'string', 'description' => 'Machine name of the module to enable'],
        ],
        'required' => ['module_name'],
      ],
    );

    $builder->addTool(
      handler: fn(string $module_name) => $this->writeTools->disableModule($module_name),
      name: 'disable_module',
      description: 'Uninstall a Drupal module. Runs uninstall hooks and removes module data. Restart the MCP server afterward.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module_name' => ['type' => 'string', 'description' => 'Machine name of the module to uninstall'],
        ],
        'required' => ['module_name'],
      ],
    );

    $builder->addTool(
      handler: fn(string $title, string $download_url) => $this->writeTools->createTestDataset($title, $download_url),
      name: 'create_test_dataset',
      description: 'Create a minimal dataset with one CSV distribution. Returns the dataset UUID. Follow up with list_distributions to get the resource_id, then import_resource to trigger datastore import.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'title' => ['type' => 'string', 'description' => 'Dataset title'],
          'download_url' => ['type' => 'string', 'description' => 'URL to a CSV file for the distribution'],
        ],
        'required' => ['title', 'download_url'],
      ],
    );

    $builder->addTool(
      handler: fn(string $resource_id, bool $deferred = FALSE) => $this->writeTools->importResource($resource_id, $deferred),
      name: 'import_resource',
      description: 'Trigger datastore import for a resource. Runs synchronously by default (suitable for small CSVs). Set deferred=true to queue for background processing.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => ['type' => 'string', 'description' => 'Resource ID in identifier__version format (from list_distributions)'],
          'deferred' => ['type' => 'boolean', 'description' => 'Queue for background processing instead of running inline', 'default' => FALSE],
        ],
        'required' => ['resource_id'],
      ],
    );
  }

  protected function registerDrupalTools(Server\Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $group = NULL) => $this->drupalTools->listEntityTypes($group),
      name: 'list_entity_types',
      description: 'List Drupal entity types with bundles. Filter by group: "content" or "configuration".',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'group' => ['type' => 'string', 'description' => 'Filter by group: "content" or "configuration"', 'enum' => ['content', 'configuration']],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $entity_type_id, ?string $bundle = NULL) => $this->drupalTools->getEntityFields($entity_type_id, $bundle),
      name: 'get_entity_fields',
      description: 'Get field definitions for an entity type. Provide bundle for all fields; omit for base fields only (auto-resolves for non-bundleable entities like user).',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'entity_type_id' => ['type' => 'string', 'description' => 'Entity type ID (e.g. node, user, taxonomy_term)'],
          'bundle' => ['type' => 'string', 'description' => 'Bundle name (e.g. article, page). Omit for base fields only.'],
        ],
        'required' => ['entity_type_id'],
      ],
    );

    $builder->addTool(
      handler: fn(?string $name_contains = NULL) => $this->drupalTools->listModules($name_contains),
      name: 'list_modules',
      description: 'List enabled Drupal modules with metadata. Optionally filter by substring match on machine name.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'name_contains' => ['type' => 'string', 'description' => 'Filter modules whose machine name contains this substring'],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(?string $name = NULL, ?string $prefix = NULL) => $this->drupalTools->getConfig($name, $prefix),
      name: 'get_config',
      description: 'Get Drupal configuration. Provide "name" for full config values (e.g. system.site), or "prefix" to list matching config names (e.g. system.).',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'name' => ['type' => 'string', 'description' => 'Full config name (e.g. system.site)'],
          'prefix' => ['type' => 'string', 'description' => 'Config name prefix to list (e.g. system.)'],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $type) => $this->drupalTools->listPlugins($type),
      name: 'list_plugins',
      description: 'List plugin definitions for a plugin type. Common types: block, field.field_type, field.widget, field.formatter, queue_worker, action, condition, element_info.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'type' => ['type' => 'string', 'description' => 'Plugin type (e.g. block, field.field_type, queue_worker)'],
        ],
        'required' => ['type'],
      ],
    );

    $builder->addTool(
      handler: fn(?string $route_name = NULL, ?string $path = NULL) => $this->drupalTools->getRouteInfo($route_name, $path),
      name: 'get_route_info',
      description: 'Get route information. Provide "route_name" for exact lookup or "path" to search by path pattern. Returns path, controller, methods, and access requirements.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'route_name' => ['type' => 'string', 'description' => 'Exact route name (e.g. dkan.metastore.get)'],
          'path' => ['type' => 'string', 'description' => 'Path pattern to search (e.g. /api/1/)'],
        ],
      ],
    );
  }

}
