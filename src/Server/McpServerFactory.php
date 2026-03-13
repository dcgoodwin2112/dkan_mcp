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
      description: 'Get aggregated dataset lineage: distributions, resource versions, import status, and perspectives — all in one call.',
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
          'resource_id' => ['type' => 'string', 'description' => 'Distribution UUID to query'],
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
          'resource_id' => ['type' => 'string', 'description' => 'Distribution UUID'],
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
          'resource_id' => ['type' => 'string', 'description' => 'Distribution UUID'],
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
      description: 'Get event details: declaring class, constant name, module, and registered subscribers with class and method names.',
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
      description: 'Trace the full reference chain for a resource: distribution UUID or resource_id (identifier__version) → perspectives (source, local_file, local_url) → datastore table name and import status.',
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
      description: 'Get service details: class name, constructor dependencies (type hints), and public method signatures with parameter types and return types.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'service_id' => ['type' => 'string', 'description' => 'Drupal service ID (e.g. dkan.metastore.service)'],
        ],
        'required' => ['service_id'],
      ],
    );
  }

}
