> **Deprecated**: This document covers the 40-tool suite and is no longer current. See [tool-suite-review.md](tool-suite-review.md) for the comprehensive 45-tool assessment.

# MCP Tool Expansion Analysis

Analysis of the current 40-tool suite (updated after implementing items 1-5): where the tools deliver the most value, concrete expansion opportunities with difficulty estimates, and strategies for demonstrating impact to other developers.

## Where the Tools Deliver the Most Value

The 40 tools serve five distinct use cases, ranked by impact:

### 1. Custom Module Development Against DKAN (Primary Design Target)

The closed-loop workflow the toolset was built for: discover services, understand APIs, write code, validate against live data. Experiment 2 scored MCP 100/100 because the agent could resolve service signatures, event payloads, and permission definitions without reading source. The tools that matter most:

- `get_service_info` + `get_class_info` — eliminates reading services.yml + source to wire DI
- `get_event_info` (with `dispatch_payload`) — eliminates tracing dispatch sites
- `check_permissions` — catches real bugs (found the orphaned `administer dkan` reference)
- `resolve_resource` — demystifies DKAN's resource lifecycle

### 2. Data Exploration and Analysis

An agent can search, discover schemas, and query across all datasets without any DKAN knowledge. Valuable for:

- Data analysts building reports or dashboards
- Answering ad-hoc questions about catalog contents
- Validating data quality before building features

### 3. Onboarding to DKAN

A developer new to DKAN can use `list_services`, `list_events`, `list_permissions`, `list_entity_types` to build a mental model of the system in minutes. The introspection tools make the runtime architecture self-documenting.

### 4. Debugging and Troubleshooting

`resolve_resource` traces the full reference chain. `get_import_status` checks import state. `check_permissions` finds misconfigurations. These replace chains of Drush commands and database queries.

### 5. DevOps / Site Administration

Harvest monitoring, import triggering, cache management, module operations. Useful but less differentiated — thin wrappers around Drush commands.

## Expansion Opportunities

### 1. Datastore Aggregation (SUM, AVG, COUNT, MIN, MAX, GROUP BY)

**Impact: Very High | Difficulty: Low-Medium**

DKAN's query API already fully supports aggregation — `sum`, `count`, `avg`, `max`, `min` via expression objects, plus `groupings` for GROUP BY. The current `query_datastore` MCP tool ignores all of this. To answer "what's the average asthma prevalence?" an agent must fetch all 106 rows and compute client-side. With aggregation exposed, it's one call returning one row.

This transforms the data analysis use case. Instead of "fetch rows and describe them," agents can do real analytics: averages, totals, distributions, comparisons across groups.

**Implementation**: Add `?string $expressions` and `?string $groupings` params to `DatastoreTools::queryDatastore()`. These get passed through to `DatastoreQuery` which already validates them against the query schema. ~80 lines (tool + factory + tests).

**Risk**: The expression format is nested JSON (`{"expression": {"operator": "sum", "operands": ["column"]}, "alias": "total"}`). Consider a simplified syntax like `"sum:amount as total_amount"` that the tool parses into the proper format.

### 2. Multi-Resource Joins

**Impact: High | Difficulty: Medium**

Also already supported by DKAN. The API accepts multiple resources with aliases and a `joins` array with cross-resource conditions. This enables "correlate asthma prevalence with tobacco usage by state" — currently impossible without fetching both datasets separately and merging client-side.

**Implementation**: New `query_datastore_join` tool (separate from `query_datastore` because the parameter shape is fundamentally different). Accepts `resources` array with aliases, `joins` array with conditions, and cross-resource `properties`. ~140 lines.

**Risk**: Agents need resource IDs for both datasets and must call `list_distributions` for each first. Consider accepting dataset UUIDs in addition to resource IDs. The join condition format is verbose — consider a shorthand like `"t.state = l.state_abbreviation"`.

### 3. Site Health Overview

**Impact: High | Difficulty: Low**

A single `get_site_status` call returning: dataset count, distributions by format (CSV: 8, ZIP: 2, PDF: 1), import status summary (done: 10, pending: 0, error: 0), harvest plan count and last run status, enabled DKAN modules, DKAN version.

Orienting an agent to a new site currently takes 5+ tool calls. A status overview condenses this into one round trip, saving tokens and establishing context immediately. Also the tool you'd show first in a demo.

**Implementation**: Aggregation of existing service calls — `MetastoreService::count()`, iterate distributions for format breakdown, `DatastoreService` for import counts, `HarvestService::getAllHarvestIds()`. ~135 lines (new `StatusTools` class + factory + tests).

**Risk**: If the site has hundreds of datasets, iterating all distributions for format counts could be slow. Consider caching or sampling.

### 4. Dataset Metadata CRUD (Update, Delete)

**Impact: Medium-High | Difficulty: Low**

`MetastoreService` already has `put()`, `patch()`, and `delete()` methods. The MCP module only exposes `create_test_dataset`. Adding update and delete enables automated content management, data pipeline workflows, and cleanup operations.

**Implementation**: Three methods wrapping `MetastoreService::put()`, `patch()`, `delete()`. ~120 lines (tools + factory + tests). All get `readOnlyHint: FALSE` annotations.

**Risk**: `deleteDataset` triggers cascade deletion of distributions and datastore tables. The tool description must be explicit. Consider requiring a `confirm: true` parameter.

### 5. Watchdog/Log Access

**Impact: Medium | Difficulty: Low**

When an import fails or a permission is denied, the agent has no way to see the error. It either guesses or asks the developer to check. Drupal stores logs in the `watchdog` table (when `dblog` is enabled).

**Implementation**: `getRecentLogs(?string $type, ?int $severity, int $limit = 20)` — query `watchdog` table, format messages by replacing placeholders from serialized `variables`. ~85 lines.

**Risk**: The `dblog` module might not be enabled (sites can use `syslog`). The tool should check and return a clear error.

### 6. Queue Inspection

**Impact: Medium | Difficulty: Low**

DKAN uses Drupal queues for datastore imports, resource localization, and harvest operations. When an import seems stuck, the agent can't tell if it's queued, processing, or failed.

**Implementation**: `getQueueStatus(?string $queueName)` — use `QueueFactory::get($name)->numberOfItems()`. If no name given, check known DKAN queues (`datastore_import`, `localize_import`, `orphan_reference_processor`). ~60 lines.

**Risk**: Minimal. `numberOfItems()` is a simple count query.

### 7. Datastore Statistics

**Impact: Medium | Difficulty: Medium**

A `get_datastore_stats(resource_id)` tool returning per-column: null count, distinct count, min/max for numeric columns. Replaces fetching all rows to understand data quality.

**Implementation**: Build SQL aggregation queries per column against the datastore table. ~120 lines.

**Risk**: Large tables with many columns means many SQL queries. Consider limiting to first N columns or accepting a column filter. MIN/MAX on text columns may not be meaningful.

### 8. Cross-Resource Column Search

**Impact: Medium-High | Difficulty: Low-Medium**

Finding which resources contain a specific type of column (date fields, geographic coordinates, monetary amounts) currently requires calling `get_datastore_schema` for every imported resource and scanning the results. On this site (11 CSV resources), that's 11 MCP calls returning ~6,800 tokens. On a production catalog with hundreds of resources, it's prohibitive.

A `search_columns` tool that searches column names (and optionally descriptions) across all datastore resources would collapse this to a single call. Real use cases from testing:
- "Which datasets have date fields?" — needed for time-series analysis
- "Which resources have lat/lon columns?" — needed for geographic visualization
- "Find all columns containing 'price' or 'cost'" — needed for financial analysis

**Implementation**: Iterate all datasets via `MetastoreService::getAll()`, resolve resource IDs via `DatasetInfo::gather()`, call `DatastoreService::getStorage()->getSchema()` for each, regex-match column names/descriptions. Return matches grouped by dataset with resource IDs. ~100 lines (new method on `DatastoreTools` + factory + tests).

**Performance**: Cache or limit to imported resources only (skip pending/error). On large catalogs, cap at first 200 resources with a `sampled` flag (same pattern as `get_site_status`).

**Risk**: Minimal. Read-only, uses existing service calls. The main concern is performance on very large catalogs.

### 9. Configuration Write

**Impact: Low-Medium | Difficulty: Low**

`get_config` reads config, but can't write. Useful for toggling feature flags or adjusting settings. Drupal's `ConfigFactory::getEditable($name)->set($key, $value)->save()` is straightforward.

**Implementation**: `setConfig(string $name, string $key, mixed $value)`. ~50 lines.

**Risk**: High blast radius. Bad config can break a site. Must be write-annotated. Consider restricting to known-safe config names.

## Summary Table

| Expansion | Impact | Difficulty | New Tools | Est. Lines | Key Enabler | Status |
|---|---|---|---|---|---|---|
| **Datastore aggregation** | Very High | Low-Medium | 0 (extend) | ~80 | DKAN already supports it | ✅ Done |
| **Multi-resource joins** | High | Medium | 1 | ~140 | DKAN already supports it | ✅ Done |
| **Site health overview** | High | Low | 1 | ~135 | Aggregation of existing calls | ✅ Done |
| **Dataset CRUD** | Medium-High | Low | 3 | ~120 | `MetastoreService` has `put/patch/delete` | ✅ Done |
| **Watchdog logs** | Medium | Low | 2 | ~85 | Direct `watchdog` table query | ✅ Done |
| **Queue inspection** | Medium | Low | 1 | ~60 | `QueueFactory::get()->numberOfItems()` | ✅ Done |
| **Datastore statistics** | Medium | Medium | 1 | ~120 | Custom SQL aggregation | ✅ Done |
| **Cross-resource column search** | Medium-High | Low-Medium | 1 | ~100 | Schema iteration + regex matching | ✅ Done |
| **Config write** | Low-Medium | Low | 1 | ~50 | `ConfigFactory::getEditable()` | Deferred |

## Recommended Priority Order

1. ~~**Datastore aggregation**~~ ✅ Implemented — `query_datastore` now supports `expressions` and `groupings` parameters.
2. ~~**Site health overview**~~ ✅ Implemented — `get_site_status` tool added via new `StatusTools` class.
3. ~~**Multi-resource joins**~~ ✅ Implemented — `query_datastore_join` tool added to `DatastoreTools`.
4. ~~**Cross-resource column search**~~ ✅ Implemented — `search_columns` tool added to `DatastoreTools`.
5. ~~**Watchdog logs**~~ ✅ Implemented — `get_recent_logs` and `get_log_types` tools added via new `LogTools` class.
6. ~~**Dataset CRUD**~~ ✅ Implemented — `update_dataset`, `patch_dataset`, `delete_dataset` tools added to `WriteTools`.
7. ~~**Queue inspection**~~ ✅ Implemented — `get_queue_status` tool added to `StatusTools`.
8. ~~**Datastore statistics**~~ ✅ Implemented — `get_datastore_stats` tool added to `DatastoreTools`. Uses raw SQL via `Connection` for `COUNT(DISTINCT)` and null counting not supported by DKAN's query API.
9. **Config write** — deferred. Useful but high blast radius; no concrete use case yet.

Items 1-8 are complete (40 → 45 tools). Expansion list closed.

## Demonstrating Impact

### 1. Side-by-Side Demo (Lowest Effort, Highest Clarity)

Show two terminals running the same prompt ("Find all healthcare datasets and summarize them"):

| Metric | MCP | Non-MCP | Improvement |
|---|---|---|---|
| Total tool calls | 8 | 14 | 43% fewer |
| Failed calls | 0 | 8 | 100% reduction |
| Sequential rounds | 3 | 6 | 50% fewer |
| Estimated tokens | ~13k | ~46k | 72% fewer |
| ID format errors | 0 | 4 | Eliminated |

### 2. Module Build Experiment Results (Already Done)

Experiments 1 and 2 produced scored results:

- Experiment 1: MCP 91 vs Files 68 (module development task)
- Experiment 2: MCP 100 vs Files 99 (after 4 tool improvements)

Key findings: MCP eliminated ID format confusion, service wiring errors, and event payload guessing. Code diffs show MCP-guided code used precise type hints (`Event $event`, `MetastoreItemInterface` methods) vs defensive checks (`method_exists`).

### 3. Developer Onboarding Benchmark

Timed exercise: "Build a Drupal block that displays the most recently updated dataset's title and row count." One developer with MCP tools, one with docs + source. Measure time to working code, wrong assumptions, and final code quality.

### 4. Error-Prevention Catalog

| Error Class | Example | Preventing Tool |
|---|---|---|
| ID format confusion | UUID passed to datastore endpoint | `list_distributions` returns `resource_id` |
| Wrong service constructor | Missing or wrong type hint | `get_service_info` |
| Orphaned permissions | Route references undefined permission | `check_permissions` |
| Event payload guessing | `getData()` returns `mixed` | `get_event_info` + `dispatch_payload` |
| Stale schema assumptions | Columns changed after re-import | `get_datastore_schema` |
| Import-before-ready | Querying unfinished import | `get_import_status` |

### 5. Token Cost Analysis

At Opus pricing, MCP is ~3.5x cheaper per interaction for data exploration tasks. For a team running dozens of agent-assisted tasks per day, this adds up. Savings come from structured responses, zero failed calls, and fewer rounds.

### 6. Interactive Workshop

Shared DKAN instance with MCP server. Each developer gets 30 minutes to explore the catalog with `search_datasets` and `query_datastore`, build a module with `/scaffold-drupal-service` and `/add-event-subscriber`, and validate with `/validate-module`. Personal experience is more persuasive than any benchmark.
