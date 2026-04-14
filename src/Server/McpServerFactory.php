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
use Drupal\dkan_mcp\Tools\StatusTools;
use Drupal\dkan_mcp\Tools\LogTools;
use Drupal\dkan_mcp\Tools\WriteTools;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;

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
    protected StatusTools $statusTools,
    protected LogTools $logTools,
  ) {}

  /**
   * Create a configured MCP Server with all tools registered.
   */
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
    $this->registerStatusTools($builder);
    $this->registerLogTools($builder);

    return $builder->build();
  }

  /**
   * Register metastore tools.
   */
  protected function registerMetastoreTools(Builder $builder): void {
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
      handler: fn(string $schema_id) => $this->metastoreTools->getSchema($schema_id),
      name: 'get_schema',
      description: 'Get a JSON Schema definition by schema ID (e.g. dataset, distribution, keyword). Use list_schemas to discover available IDs.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'schema_id' => ['type' => 'string', 'description' => 'Schema ID (e.g. dataset, distribution, keyword)'],
        ],
        'required' => ['schema_id'],
      ],
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

  /**
   * Register datastore tools.
   */
  protected function registerDatastoreTools(Builder $builder): void {
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
        ?string $expressions = NULL,
        ?string $groupings = NULL,
      ) => $this->datastoreTools->queryDatastore(
        $resource_id, $columns, $conditions, $sort_field, $sort_direction, $limit, $offset, $expressions, $groupings,
      ),
      name: 'query_datastore',
      description: 'Query a datastore resource table with optional filters, sorting, pagination, aggregation (sum, count, avg, max, min with GROUP BY), and arithmetic expressions (+, -, *, /, %). Use get_datastore_schema first to discover available columns.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => [
            'type' => 'string',
            'description' => 'Resource ID in identifier__version format (from list_distributions)',
          ],
          'columns' => ['type' => 'string', 'description' => 'Comma-separated column names to return (omit for all)'],
          'conditions' => [
            'type' => 'string',
            'description' => 'JSON array of condition objects: [{"property":"col","value":"val","operator":"="}]. Operators: =, <>, <, <=, >, >=, like, contains, starts with, in, not in, between. Supports conditionGroup for OR logic: [{"groupOperator":"or","conditions":[...]}]',
          ],
          'sort_field' => ['type' => 'string', 'description' => 'Column name to sort by'],
          'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
          'limit' => ['type' => 'integer', 'description' => 'Max rows to return (1-500)', 'default' => 100],
          'offset' => ['type' => 'integer', 'description' => 'Number of rows to skip', 'default' => 0],
          'expressions' => [
            'type' => 'string',
            'description' => 'JSON array of expressions: [{"operator":"sum","operands":["column"],"alias":"total"}]. Aggregate operators: sum, count, avg, max, min (1 operand, use with groupings). Arithmetic operators: +, -, *, /, % (2 operands, row-level computed columns). Cannot mix aggregate and arithmetic in one query.',
          ],
          'groupings' => [
            'type' => 'string',
            'description' => 'Comma-separated column names to GROUP BY. Required when using aggregate expressions. All non-aggregated columns must be listed here.',
          ],
        ],
        'required' => ['resource_id'],
      ],
    );

    $builder->addTool(
      handler: fn(
        string $resource_id,
        string $join_resource_id,
        string $join_on,
        ?string $columns = NULL,
        ?string $conditions = NULL,
        ?string $sort_field = NULL,
        string $sort_direction = 'asc',
        int $limit = 100,
        int $offset = 0,
        ?string $expressions = NULL,
        ?string $groupings = NULL,
      ) => $this->datastoreTools->queryDatastoreJoin(
        $resource_id, $join_resource_id, $join_on, $columns, $conditions,
        $sort_field, $sort_direction, $limit, $offset, $expressions, $groupings,
      ),
      name: 'query_datastore_join',
      description: 'Join and query two datastore resources with optional aggregation. Use get_datastore_schema on both resources first to discover columns. Primary resource is aliased as "t", joined resource as "j". Qualify columns with alias prefix (e.g., "t.state,j.smoking_rate").',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => [
            'type' => 'string',
            'description' => 'Primary resource ID (identifier__version format)',
          ],
          'join_resource_id' => [
            'type' => 'string',
            'description' => 'Resource ID to join with (identifier__version format)',
          ],
          'join_on' => [
            'type' => 'string',
            'description' => 'Join condition. Simple: "state=state_abbreviation" (primary_col=join_col). JSON for non-equality: {"left":"t.col","right":"j.col","operator":"="}',
          ],
          'columns' => [
            'type' => 'string',
            'description' => 'Comma-separated columns with optional alias prefix: "t.state,j.rate". Unqualified columns default to primary resource (t). Omit for all columns.',
          ],
          'conditions' => [
            'type' => 'string',
            'description' => 'JSON array of conditions. Add "resource":"j" to filter on joined table: [{"resource":"j","property":"col","value":"val","operator":"="}]. Supports conditionGroup for OR logic.',
          ],
          'sort_field' => [
            'type' => 'string',
            'description' => 'Column to sort by, with optional alias prefix (e.g., "j.rate")',
          ],
          'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
          'limit' => ['type' => 'integer', 'description' => 'Max rows (1-500)', 'default' => 100],
          'offset' => ['type' => 'integer', 'description' => 'Rows to skip', 'default' => 0],
          'expressions' => [
            'type' => 'string',
            'description' => 'JSON array of expressions (same format as query_datastore). Aggregate operators: sum, count, avg, max, min. Arithmetic: +, -, *, /, %. Cannot mix types.',
          ],
          'groupings' => [
            'type' => 'string',
            'description' => 'Comma-separated GROUP BY columns with optional alias prefix (e.g., "t.state,j.year"). Required when using aggregate expressions.',
          ],
        ],
        'required' => ['resource_id', 'join_resource_id', 'join_on'],
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
      handler: fn(
        string $search_term,
        string $search_in = 'name',
        int $limit = 100,
      ) => $this->datastoreTools->searchColumns($search_term, $search_in, $limit),
      name: 'search_columns',
      description: 'Search column names and descriptions across all imported datastore resources. Use to find which datasets contain specific types of data (e.g., "state", "price", "date") before querying or joining.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'search_term' => [
            'type' => 'string',
            'description' => 'Column name or description substring to search (case-insensitive)',
          ],
          'search_in' => [
            'type' => 'string',
            'enum' => ['name', 'description', 'both'],
            'description' => 'Where to search: column names, descriptions, or both',
            'default' => 'name',
          ],
          'limit' => [
            'type' => 'integer',
            'description' => 'Max matches to return (default 100)',
            'default' => 100,
          ],
        ],
        'required' => ['search_term'],
      ],
    );

    $builder->addTool(
      handler: fn(string $resource_id, ?string $columns = NULL) => $this->datastoreTools->getDatastoreStats($resource_id, $columns),
      name: 'get_datastore_stats',
      description: 'Get per-column statistics for a datastore resource: null count, distinct count, min, max, and total row count. Use to understand data quality and distribution before querying. Note: DKAN stores CSV data as text, so min/max use lexicographic ordering (e.g., "9" > "10000"). For true numeric min/max, use query_datastore with min/max expressions.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'resource_id' => ['type' => 'string', 'description' => 'Resource ID in identifier__version format'],
          'columns' => [
            'type' => 'string',
            'description' => 'Comma-separated column names to analyze. Omit for all columns.',
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

  /**
   * Register search tools.
   */
  protected function registerSearchTools(Builder $builder): void {
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

  /**
   * Register harvest tools.
   */
  protected function registerHarvestTools(Builder $builder): void {
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

    $write = new ToolAnnotations(readOnlyHint: FALSE);

    $builder->addTool(
      handler: fn(string $plan) => $this->harvestTools->registerHarvest($plan),
      name: 'register_harvest',
      description: 'Register a new harvest plan. The plan JSON must include identifier, extract (type + uri), and load properties.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'plan' => [
            'type' => 'string',
            'description' => 'Harvest plan as a JSON string with identifier, extract, and load properties',
          ],
        ],
        'required' => ['plan'],
      ],
    );

    $builder->addTool(
      handler: fn(string $plan_id) => $this->harvestTools->runHarvest($plan_id),
      name: 'run_harvest',
      description: 'Execute a harvest run for a registered plan. Fetches data from the source and creates/updates datasets.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'plan_id' => ['type' => 'string', 'description' => 'Harvest plan ID'],
        ],
        'required' => ['plan_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $plan_id) => $this->harvestTools->deregisterHarvest($plan_id),
      name: 'deregister_harvest',
      description: 'Remove a registered harvest plan. Does not delete datasets that were previously harvested.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'plan_id' => ['type' => 'string', 'description' => 'Harvest plan ID'],
        ],
        'required' => ['plan_id'],
      ],
    );
  }

  /**
   * Register event tools.
   */
  protected function registerEventTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $module = NULL, bool $brief = FALSE) => $this->eventTools->listEvents($module, $brief),
      name: 'list_events',
      description: 'List DKAN events with constant names, string values, and declaring classes. Filter by module (metastore, datastore, common). Use brief=true for event name strings only.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module' => [
            'type' => 'string',
            'description' => 'Module name to filter (e.g. metastore, datastore). Omit for all DKAN events.',
          ],
          'brief' => [
            'type' => 'boolean',
            'description' => 'Return event name strings only (no constants, classes). Saves tokens.',
            'default' => FALSE,
          ],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $event_name, ?string $fields = NULL) => $this->eventTools->getEventInfo($event_name, $fields),
      name: 'get_event_info',
      description: 'Get event details: declaring class, constant name, module, subscribers, event class (from subscriber type hints), event class methods, and dispatch payload type (the actual object passed to getData() at the dispatch site). For events using Drupal\common\Events\Event, the dispatch_payload field shows what getData() returns.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'event_name' => [
            'type' => 'string',
            'description' => 'Event name string (e.g. dkan_metastore_dataset_update)',
          ],
          'fields' => [
            'type' => 'string',
            'description' => 'Comma-separated field names to include (e.g. "constant,dispatch_payload"). Omit for all fields.',
          ],
        ],
        'required' => ['event_name'],
      ],
    );
  }

  /**
   * Register resource tools.
   */
  protected function registerResourceTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(string $id) => $this->resourceTools->resolveResource($id),
      name: 'resolve_resource',
      description: 'Trace the full reference chain for a resource: distribution UUID or resource_id (identifier__version) → perspectives (source, local_file, local_url) → datastore table name, import status, and dataset_uuid (reverse lookup to the owning dataset).',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'id' => [
            'type' => 'string',
            'description' => 'Distribution UUID or resource_id in identifier__version format',
          ],
        ],
        'required' => ['id'],
      ],
    );
  }

  /**
   * Register permission tools.
   */
  protected function registerPermissionTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $module = NULL) => $this->permissionTools->listPermissions($module),
      name: 'list_permissions',
      description: 'List DKAN permissions with metadata (title, description, provider module). Filter by module name.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module' => [
            'type' => 'string',
            'description' => 'Module name to filter (e.g. harvest, datastore, metastore). Omit for all DKAN permissions.',
          ],
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

  /**
   * Register service introspection tools.
   */
  protected function registerServiceTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $module = NULL, bool $brief = FALSE) => $this->serviceTools->listServices($module, $brief),
      name: 'list_services',
      description: 'List DKAN service IDs with class names. Filter by module (metastore, datastore, harvest, common). Use brief=true for ID-only list.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'module' => [
            'type' => 'string',
            'description' => 'Module name to filter (e.g. metastore, datastore). Omit for all DKAN services.',
          ],
          'brief' => [
            'type' => 'boolean',
            'description' => 'Return service IDs only (no class names). Saves tokens.',
            'default' => FALSE,
          ],
        ],
      ],
    );

    $builder->addTool(
      handler: fn(string $service_id, ?string $methods = NULL, bool $include_yaml = TRUE) => $this->serviceTools->getServiceInfo($service_id, $methods, $include_yaml),
      name: 'get_service_info',
      description: 'Get service details: class name, constructor dependencies, public method signatures, and YAML definition (arguments, calls/setter injection, tags). Shows constructor params only — use get_class_info on the service class to find setter methods from calls: entries.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'service_id' => ['type' => 'string', 'description' => 'Drupal service ID (e.g. dkan.metastore.service)'],
          'methods' => [
            'type' => 'string',
            'description' => 'Comma-separated glob patterns to filter methods (e.g. "get*", "get*,set*"). Omit for all methods.',
          ],
          'include_yaml' => [
            'type' => 'boolean',
            'description' => 'Include YAML service definition. Set false to save tokens when YAML is not needed.',
            'default' => TRUE,
          ],
        ],
        'required' => ['service_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $class_name, ?string $methods = NULL) => $this->serviceTools->getClassInfo($class_name, $methods),
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
          'methods' => [
            'type' => 'string',
            'description' => 'Comma-separated glob patterns to filter methods (e.g. "get*", "query*,fetch*"). Omit for all methods.',
          ],
        ],
        'required' => ['class_name'],
      ],
    );

    $builder->addTool(
      handler: fn(string $service_id, ?string $method = NULL, int $depth = 1) => $this->serviceTools->discoverApi($service_id, $method, $depth),
      name: 'discover_api',
      description: 'Discover a service\'s API and follow return types in one call. Returns service info (without YAML) plus class info for return types. Use instead of chaining get_service_info + get_class_info.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'service_id' => ['type' => 'string', 'description' => 'Drupal service ID (e.g. dkan.metastore.service)'],
          'method' => [
            'type' => 'string',
            'description' => 'Glob pattern to filter methods (e.g. "getStorage"). Return types of matching methods are followed.',
          ],
          'depth' => [
            'type' => 'integer',
            'description' => 'How many levels of return types to follow (1-2). Default 1.',
            'default' => 1,
          ],
        ],
        'required' => ['service_id'],
      ],
    );
  }

  /**
   * Register write operation tools.
   */
  protected function registerWriteTools(Builder $builder): void {
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
          'resource_id' => [
            'type' => 'string',
            'description' => 'Resource ID in identifier__version format (from list_distributions)',
          ],
          'deferred' => [
            'type' => 'boolean',
            'description' => 'Queue for background processing instead of running inline',
            'default' => FALSE,
          ],
        ],
        'required' => ['resource_id'],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier, string $metadata) => $this->writeTools->updateDataset($identifier, $metadata),
      name: 'update_dataset',
      description: 'Full replacement of dataset metadata (PUT semantics). Can upsert — creates the dataset if it does not exist. The metadata must be a complete dataset object as a JSON string. Returns {status, identifier, new} where "new" indicates whether a new dataset was created.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Dataset UUID'],
          'metadata' => ['type' => 'string', 'description' => 'Complete dataset metadata as a JSON string'],
        ],
        'required' => ['identifier', 'metadata'],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier, string $metadata) => $this->writeTools->patchDataset($identifier, $metadata),
      name: 'patch_dataset',
      description: 'Partial update of dataset metadata using JSON Merge Patch (RFC 7396). Send only the fields you want to change as a JSON object (e.g., {"title": "New Title"}). Fields not included are left unchanged.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Dataset UUID'],
          'metadata' => ['type' => 'string', 'description' => 'JSON object with only the fields to change'],
        ],
        'required' => ['identifier', 'metadata'],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier) => $this->writeTools->deleteDataset($identifier),
      name: 'delete_dataset',
      description: 'Delete a dataset and cascade-delete its distributions and datastore tables. This is destructive and cannot be undone.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Dataset UUID'],
        ],
        'required' => ['identifier'],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier) => $this->writeTools->publishDataset($identifier),
      name: 'publish_dataset',
      description: 'Publish a dataset to make it publicly visible. The dataset must already exist.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Dataset UUID'],
        ],
        'required' => ['identifier'],
      ],
    );

    $builder->addTool(
      handler: fn(string $identifier) => $this->writeTools->unpublishDataset($identifier),
      name: 'unpublish_dataset',
      description: 'Unpublish (archive) a dataset to remove it from public visibility. The dataset is not deleted.',
      annotations: $write,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'identifier' => ['type' => 'string', 'description' => 'Dataset UUID'],
        ],
        'required' => ['identifier'],
      ],
    );

    $builder->addTool(
      handler: fn(string $resource_id) => $this->writeTools->dropDatastore($resource_id),
      name: 'drop_datastore',
      description: 'Drop the datastore table for a resource. Use import_resource to re-import afterward if needed.',
      annotations: $write,
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

  /**
   * Register status tools.
   */
  protected function registerStatusTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn() => $this->statusTools->getSiteStatus(),
      name: 'get_site_status',
      description: 'Get a high-level overview of the DKAN site: dataset and distribution counts (by format), import status summary (done/pending/error), harvest plan count, DKAN module versions, and Drupal version. Use this to orient on a new site before deeper exploration.',
      annotations: $readOnly,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );

    $builder->addTool(
      handler: fn(?string $queue_name = NULL) => $this->statusTools->getQueueStatus($queue_name),
      name: 'get_queue_status',
      description: 'Get queue item counts for DKAN queues. Shows how many items are waiting for processing in import, localization, and cleanup queues. Use when imports seem stuck or after triggering deferred imports.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'queue_name' => [
            'type' => 'string',
            'description' => 'Specific queue name (e.g. datastore_import). Omit for all DKAN queues.',
          ],
        ],
      ],
    );
  }

  /**
   * Register Drupal introspection tools.
   */
  protected function registerDrupalTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(?string $group = NULL) => $this->drupalTools->listEntityTypes($group),
      name: 'list_entity_types',
      description: 'List Drupal entity types with bundles. Filter by group: "content" or "configuration".',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'group' => [
            'type' => 'string',
            'description' => 'Filter by group: "content" or "configuration"',
            'enum' => ['content', 'configuration'],
          ],
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
          'entity_type_id' => [
            'type' => 'string',
            'description' => 'Entity type ID (e.g. node, user, taxonomy_term)',
          ],
          'bundle' => [
            'type' => 'string',
            'description' => 'Bundle name (e.g. article, page). Omit for base fields only.',
          ],
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
          'name_contains' => [
            'type' => 'string',
            'description' => 'Filter modules whose machine name contains this substring',
          ],
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

  /**
   * Register log tools.
   */
  protected function registerLogTools(Builder $builder): void {
    $readOnly = new ToolAnnotations(readOnlyHint: TRUE);

    $builder->addTool(
      handler: fn(
        ?string $type = NULL,
        ?int $severity = NULL,
        int $limit = 25,
        int $offset = 0,
      ) => $this->logTools->getRecentLogs($type, $severity, $limit, $offset),
      name: 'get_recent_logs',
      description: 'Get recent watchdog log entries with optional filters. Use to diagnose import failures, permission denials, and runtime errors without leaving the conversation.',
      annotations: $readOnly,
      inputSchema: [
        'type' => 'object',
        'properties' => [
          'type' => ['type' => 'string', 'description' => 'Filter by log type (e.g. "dkan", "php", "user", "cron")'],
          'severity' => [
            'type' => 'integer',
            'description' => 'Max severity level 0-7. 0=Emergency, 3=Error, 4=Warning, 7=Debug.',
          ],
          'limit' => ['type' => 'integer', 'description' => 'Max entries to return (1-100)', 'default' => 25],
          'offset' => ['type' => 'integer', 'description' => 'Pagination offset', 'default' => 0],
        ],
      ],
    );

    $builder->addTool(
      handler: fn() => $this->logTools->getLogTypes(),
      name: 'get_log_types',
      description: 'List distinct watchdog log types with entry counts. Use to discover what types of log entries exist before filtering with get_recent_logs.',
      annotations: $readOnly,
      inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
    );
  }

}
