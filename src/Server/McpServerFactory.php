<?php

namespace Drupal\dkan_mcp\Server;

use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_mcp\Tools\HarvestTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_mcp\Tools\ResourceTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Drupal\dkan_mcp\Tools\StatusTools;
use Drupal\dkan_mcp\Tools\WriteTools;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;

/**
 * Builds a configured MCP Server with DKAN tools registered.
 *
 * Tools are declared in self::TOOL_GROUPS as a group => specs map. Each spec
 * binds a tool name to a [Class::class, 'method'] handler. The MCP SDK reflects
 * the backing method to auto-generate the input schema (types, required set,
 * and per-property descriptions from @param docblocks) unless the spec supplies
 * an explicit 'input' schema. Tool descriptions are supplied explicitly to keep
 * the agent-facing prose separate from internal developer docblocks.
 *
 * Handlers are resolved against a ToolServiceContainer so the SDK invokes the
 * methods on the DI-built service instances this factory receives.
 */
class McpServerFactory {

  public function __construct(
    protected MetastoreTools $metastoreTools,
    protected DatastoreTools $datastoreTools,
    protected SearchTools $searchTools,
    protected HarvestTools $harvestTools,
    protected ResourceTools $resourceTools,
    protected WriteTools $writeTools,
    protected StatusTools $statusTools,
  ) {}

  /**
   * Explicit input schema for query_datastore (operator/expression detail).
   */
  private const SCHEMA_QUERY_DATASTORE = [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions)',
      ],
      'columns' => ['type' => 'string', 'description' => 'Comma-separated column names to return (omit for all)'],
      'conditions' => [
        'type' => 'string',
        'description' => 'JSON array of condition objects: [{"property":"col","value":"val","operator":"="}]. Operators: =, <>, <, <=, >, >=, like, contains, starts with, in, not in, between. Supports conditionGroup for OR logic: [{"groupOperator":"or","conditions":[...]}]',
      ],
      'sortField' => ['type' => 'string', 'description' => 'Column name to sort by'],
      'sortDirection' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
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
    'required' => ['resourceId'],
  ];

  /**
   * Explicit input schema for query_datastore_join.
   */
  private const SCHEMA_QUERY_DATASTORE_JOIN = [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Primary resource ID (identifier__version format)',
      ],
      'joinResourceId' => [
        'type' => 'string',
        'description' => 'Resource ID to join with (identifier__version format)',
      ],
      'joinOn' => [
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
      'sortField' => [
        'type' => 'string',
        'description' => 'Column to sort by, with optional alias prefix (e.g., "j.rate")',
      ],
      'sortDirection' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
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
    'required' => ['resourceId', 'joinResourceId', 'joinOn'],
  ];

  /**
   * Explicit input schema for get_datastore_schema.
   *
   * Hand-written so the method's internal includeDictionary parameter is not
   * exposed as a tool argument.
   */
  private const SCHEMA_GET_DATASTORE_SCHEMA = [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Datastore resource ID (identifier__version) or a distribution UUID (from list_distributions)',
      ],
    ],
    'required' => ['resourceId'],
  ];

  /**
   * Advisory output schemas (not validated; permissive, no required fields).
   */
  private const OUT_LIST_DATASETS = [
    'type' => 'object',
    'properties' => [
      'datasets' => ['type' => 'array'],
      'total' => ['type' => 'integer'],
      'offset' => ['type' => 'integer'],
      'limit' => ['type' => 'integer'],
    ],
  ];

  private const OUT_QUERY_RESULT = [
    'type' => 'object',
    'properties' => [
      'results' => ['type' => 'array'],
      'result_count' => ['type' => 'integer'],
      'total_rows' => ['type' => 'integer'],
      'limit' => ['type' => 'integer'],
      'offset' => ['type' => 'integer'],
      'sanity_flags' => ['type' => 'object'],
    ],
  ];

  private const OUT_DATASTORE_SCHEMA = [
    'type' => 'object',
    'properties' => [
      'resource_id' => ['type' => 'string'],
      'columns' => ['type' => 'array'],
    ],
  ];

  private const OUT_IMPORT_STATUS = [
    'type' => 'object',
    'properties' => [
      'resource_id' => ['type' => 'string'],
      'status' => ['type' => 'string'],
      'num_of_rows' => ['type' => 'integer'],
      'num_of_columns' => ['type' => 'integer'],
    ],
  ];

  private const OUT_SITE_STATUS = [
    'type' => 'object',
    'properties' => [
      'datasets' => ['type' => 'object'],
      'distributions' => ['type' => 'object'],
      'imports' => ['type' => 'object'],
      'harvest' => ['type' => 'object'],
      'dkan' => ['type' => 'object'],
      'drupal' => ['type' => 'object'],
    ],
  ];

  private const OUT_WRITE_STATUS = [
    'type' => 'object',
    'properties' => [
      'status' => ['type' => 'string'],
      'identifier' => ['type' => 'string'],
      'message' => ['type' => 'string'],
    ],
  ];

  /**
   * Tool registry: group => list of tool specs.
   *
   * Spec keys: name, class, method, readOnly, description, input?, output?.
   * Group keys are stable — McpController::HTTP_TOOL_GROUPS references them.
   */
  private const TOOL_GROUPS = [
    'metastore' => [
      [
        'name' => 'list_datasets',
        'class' => MetastoreTools::class,
        'method' => 'listDatasets',
        'readOnly' => TRUE,
        'description' => 'List dataset summaries with pagination. Returns title, identifier, description, and distribution count for each dataset.',
        'output' => self::OUT_LIST_DATASETS,
      ],
      [
        'name' => 'get_dataset',
        'class' => MetastoreTools::class,
        'method' => 'getDataset',
        'readOnly' => TRUE,
        'description' => 'Get full metadata for a dataset by its UUID. Returns the complete DCAT dataset object including title, description, distributions, keywords, and all other metadata fields.',
      ],
      [
        'name' => 'list_distributions',
        'class' => MetastoreTools::class,
        'method' => 'listDistributions',
        'readOnly' => TRUE,
        'description' => 'List distributions (data files) for a dataset. Returns download URL, media type, and title for each distribution.',
      ],
      [
        'name' => 'get_distribution',
        'class' => MetastoreTools::class,
        'method' => 'getDistribution',
        'readOnly' => TRUE,
        'description' => 'Get full metadata for a distribution by its UUID.',
      ],
      [
        'name' => 'list_schemas',
        'class' => MetastoreTools::class,
        'method' => 'listSchemas',
        'readOnly' => TRUE,
        'description' => 'List available metadata schema IDs (e.g. dataset, distribution, keyword, theme).',
      ],
      [
        'name' => 'get_catalog',
        'class' => MetastoreTools::class,
        'method' => 'getCatalog',
        'readOnly' => TRUE,
        'description' => 'Get the full DCAT data catalog with all datasets and their metadata.',
      ],
      [
        'name' => 'get_schema',
        'class' => MetastoreTools::class,
        'method' => 'getSchema',
        'readOnly' => TRUE,
        'description' => 'Get a JSON Schema definition by schema ID (e.g. dataset, distribution, keyword). Use list_schemas to discover available IDs.',
      ],
      [
        'name' => 'get_data_dictionary',
        'class' => MetastoreTools::class,
        'method' => 'getDataDictionary',
        'readOnly' => TRUE,
        'description' => 'Get the data dictionary linked to a dataset or distribution. Accepts a dataset UUID or a resource id (identifier__version). Returns dictionary fields with curated titles, descriptions, and declared types — or an error if no dictionary is linked. Note: get_datastore_schema already merges dictionary fields into its column response; use this tool when you need the full dictionary independent of the datastore schema (e.g., to see fields the datastore does not expose).',
      ],
    ],
    'metastore_dev' => [
      [
        'name' => 'get_dataset_info',
        'class' => MetastoreTools::class,
        'method' => 'getDatasetInfo',
        'readOnly' => TRUE,
        'description' => 'Get aggregated dataset info including all distribution details. Returns latest_revision.distributions[] with keys: distribution_uuid, resource_id, resource_version, mime_type, source_path, importer_status ("waiting"|"done"|"error"), importer_percent_done, importer_error, table_name, fetcher_status, fetcher_percent_done, file_path. Use this to discover the actual data structure of DatasetInfo::gather().',
      ],
    ],
    'datastore' => [
      [
        'name' => 'query_datastore',
        'class' => DatastoreTools::class,
        'method' => 'queryDatastore',
        'readOnly' => TRUE,
        'description' => 'Query a datastore resource table with optional filters, sorting, pagination, aggregation (sum, count, avg, max, min with GROUP BY), and arithmetic expressions (+, -, *, /, %). Use get_datastore_schema first to discover available columns.',
        'input' => self::SCHEMA_QUERY_DATASTORE,
        'output' => self::OUT_QUERY_RESULT,
      ],
      [
        'name' => 'query_datastore_join',
        'class' => DatastoreTools::class,
        'method' => 'queryDatastoreJoin',
        'readOnly' => TRUE,
        'description' => 'Join and query two datastore resources with optional aggregation. Use get_datastore_schema on both resources first to discover columns. Primary resource is aliased as "t", joined resource as "j". Qualify columns with alias prefix (e.g., "t.state,j.smoking_rate").',
        'input' => self::SCHEMA_QUERY_DATASTORE_JOIN,
        'output' => self::OUT_QUERY_RESULT,
      ],
      [
        'name' => 'get_datastore_schema',
        'class' => DatastoreTools::class,
        'method' => 'getDatastoreSchema',
        'readOnly' => TRUE,
        'description' => 'Get column names and types for a datastore resource. Use this before querying to discover available fields.',
        'input' => self::SCHEMA_GET_DATASTORE_SCHEMA,
        'output' => self::OUT_DATASTORE_SCHEMA,
      ],
      [
        'name' => 'search_columns',
        'class' => DatastoreTools::class,
        'method' => 'searchColumns',
        'readOnly' => TRUE,
        'description' => 'Search column names and descriptions across all imported datastore resources. Use to find which datasets contain specific types of data (e.g., "state", "price", "date") before querying or joining.',
      ],
      [
        'name' => 'get_datastore_stats',
        'class' => DatastoreTools::class,
        'method' => 'getDatastoreStats',
        'readOnly' => TRUE,
        'description' => 'Get per-column statistics for a datastore resource: null count, distinct count, min, max, and total row count. Use to understand data quality and distribution before querying. Note: DKAN stores CSV data as text, so min/max use lexicographic ordering (e.g., "9" > "10000"). For true numeric min/max, use query_datastore with min/max expressions.',
      ],
      [
        'name' => 'get_import_status',
        'class' => DatastoreTools::class,
        'method' => 'getImportStatus',
        'readOnly' => TRUE,
        'description' => 'Get the import/processing status of a datastore resource.',
        'output' => self::OUT_IMPORT_STATUS,
      ],
    ],
    'search' => [
      [
        'name' => 'search_datasets',
        'class' => SearchTools::class,
        'method' => 'searchDatasets',
        'readOnly' => TRUE,
        'description' => 'Search datasets by keyword. Returns matching datasets with title, identifier, description, and relevance.',
      ],
    ],
    'harvest_read' => [
      [
        'name' => 'list_harvest_plans',
        'class' => HarvestTools::class,
        'method' => 'listHarvestPlans',
        'readOnly' => TRUE,
        'description' => 'List all registered harvest plan IDs.',
      ],
      [
        'name' => 'get_harvest_plan',
        'class' => HarvestTools::class,
        'method' => 'getHarvestPlan',
        'readOnly' => TRUE,
        'description' => 'Get harvest plan configuration: source URL, extract/transform/load settings.',
      ],
      [
        'name' => 'get_harvest_runs',
        'class' => HarvestTools::class,
        'method' => 'getHarvestRuns',
        'readOnly' => TRUE,
        'description' => 'List all runs for a harvest plan with timestamps and status.',
      ],
      [
        'name' => 'get_harvest_run_result',
        'class' => HarvestTools::class,
        'method' => 'getHarvestRunResult',
        'readOnly' => TRUE,
        'description' => 'Detailed result for a harvest run: created/updated/failed datasets. Returns latest run if no run id specified.',
      ],
    ],
    'harvest_write' => [
      [
        'name' => 'register_harvest',
        'class' => HarvestTools::class,
        'method' => 'registerHarvest',
        'readOnly' => FALSE,
        'description' => 'Register a new harvest plan. The plan JSON must include identifier, extract (type + uri), and load properties.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'run_harvest',
        'class' => HarvestTools::class,
        'method' => 'runHarvest',
        'readOnly' => FALSE,
        'description' => 'Execute a harvest run for a registered plan. Fetches data from the source and creates/updates datasets.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'deregister_harvest',
        'class' => HarvestTools::class,
        'method' => 'deregisterHarvest',
        'readOnly' => FALSE,
        'description' => 'Remove a registered harvest plan. Does not delete datasets that were previously harvested.',
        'output' => self::OUT_WRITE_STATUS,
      ],
    ],
    'resource' => [
      [
        'name' => 'resolve_resource',
        'class' => ResourceTools::class,
        'method' => 'resolveResource',
        'readOnly' => TRUE,
        'description' => 'Trace the full reference chain for a resource: distribution UUID or resource id (identifier__version) → perspectives (source, local_file, local_url) → datastore table name, import status, and dataset_uuid (reverse lookup to the owning dataset).',
      ],
    ],
    'write' => [
      [
        'name' => 'import_resource',
        'class' => WriteTools::class,
        'method' => 'importResource',
        'readOnly' => FALSE,
        'description' => 'Trigger datastore import for a resource. Runs synchronously by default (suitable for small CSVs). Set deferred=true to queue for background processing.',
      ],
      [
        'name' => 'update_dataset',
        'class' => WriteTools::class,
        'method' => 'updateDataset',
        'readOnly' => FALSE,
        'description' => 'Full replacement of dataset metadata (PUT semantics). Can upsert — creates the dataset if it does not exist. The metadata must be a complete dataset object as a JSON string. Returns {status, identifier, new} where "new" indicates whether a new dataset was created.',
      ],
      [
        'name' => 'patch_dataset',
        'class' => WriteTools::class,
        'method' => 'patchDataset',
        'readOnly' => FALSE,
        'description' => 'Partial update of dataset metadata using JSON Merge Patch (RFC 7396). Send only the fields you want to change as a JSON object (e.g., {"title": "New Title"}). Fields not included are left unchanged.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'delete_dataset',
        'class' => WriteTools::class,
        'method' => 'deleteDataset',
        'readOnly' => FALSE,
        'description' => 'Delete a dataset and cascade-delete its distributions and datastore tables. This is destructive and cannot be undone.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'publish_dataset',
        'class' => WriteTools::class,
        'method' => 'publishDataset',
        'readOnly' => FALSE,
        'description' => 'Publish a dataset to make it publicly visible. The dataset must already exist.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'unpublish_dataset',
        'class' => WriteTools::class,
        'method' => 'unpublishDataset',
        'readOnly' => FALSE,
        'description' => 'Unpublish (archive) a dataset to remove it from public visibility. The dataset is not deleted.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'drop_datastore',
        'class' => WriteTools::class,
        'method' => 'dropDatastore',
        'readOnly' => FALSE,
        'description' => 'Drop the datastore table for a resource. Use import_resource to re-import afterward if needed.',
        'output' => self::OUT_WRITE_STATUS,
      ],
      [
        'name' => 'post_metastore_item',
        'class' => WriteTools::class,
        'method' => 'postMetastoreItem',
        'readOnly' => FALSE,
        'description' => 'Create a metastore item under any schema (e.g., data-dictionary, distribution, theme, keyword). For datasets prefer update_dataset. Metadata must be a JSON string with the schema-required fields. Returns the new identifier. Use list_schemas + get_schema to discover required fields.',
      ],
      [
        'name' => 'patch_metastore_item',
        'class' => WriteTools::class,
        'method' => 'patchMetastoreItem',
        'readOnly' => FALSE,
        'description' => 'Partial update of any metastore item via JSON Merge Patch (RFC 7396). For datasets prefer patch_dataset. Send only the fields to change as a JSON object string.',
      ],
    ],
    'status' => [
      [
        'name' => 'get_site_status',
        'class' => StatusTools::class,
        'method' => 'getSiteStatus',
        'readOnly' => TRUE,
        'description' => 'Get a high-level overview of the DKAN site: dataset and distribution counts (by format), import status summary (done/pending/error), harvest plan count, DKAN module versions, and Drupal version. Use this to orient on a new site before deeper exploration.',
        'output' => self::OUT_SITE_STATUS,
      ],
      [
        'name' => 'get_queue_status',
        'class' => StatusTools::class,
        'method' => 'getQueueStatus',
        'readOnly' => TRUE,
        'description' => 'Get queue item counts for DKAN queues. Shows how many items are waiting for processing in import, localization, and cleanup queues. Use when imports seem stuck or after triggering deferred imports.',
      ],
    ],
  ];

  /**
   * Create a configured MCP Server.
   *
   * @param array|null $toolGroups
   *   Tool groups to register. NULL registers all groups. Valid keys are
   *   defined in self::TOOL_GROUPS.
   * @param \Mcp\Server\Session\SessionStoreInterface|null $sessionStore
   *   Session store for cross-request persistence. NULL uses in-memory
   *   (suitable for stdio). Use FileSessionStore for HTTP transport.
   */
  public function create(?array $toolGroups = NULL, ?SessionStoreInterface $sessionStore = NULL): Server {
    $builder = Server::builder()
      ->setServerInfo('dkan', '1.0.0')
      ->setContainer($this->toolContainer());

    if ($sessionStore) {
      $builder->setSession($sessionStore);
    }

    $groups = $toolGroups ?? array_keys(self::TOOL_GROUPS);
    foreach ($groups as $group) {
      foreach (self::TOOL_GROUPS[$group] ?? [] as $spec) {
        $builder->addTool(
          handler: [$spec['class'], $spec['method']],
          name: $spec['name'],
          description: $spec['description'],
          annotations: new ToolAnnotations(readOnlyHint: $spec['readOnly']),
          inputSchema: $spec['input'] ?? NULL,
          outputSchema: $spec['output'] ?? NULL,
        );
      }
    }

    return $builder->build();
  }

  /**
   * Build the PSR-11 container the SDK uses to resolve tool handler instances.
   */
  protected function toolContainer(): ToolServiceContainer {
    return new ToolServiceContainer([
      MetastoreTools::class => $this->metastoreTools,
      DatastoreTools::class => $this->datastoreTools,
      SearchTools::class => $this->searchTools,
      HarvestTools::class => $this->harvestTools,
      ResourceTools::class => $this->resourceTools,
      WriteTools::class => $this->writeTools,
      StatusTools::class => $this->statusTools,
    ]);
  }

}
