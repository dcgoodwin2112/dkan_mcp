# dkan_mcp Tool Suite Review

Comprehensive assessment of the 45-tool suite across 12 tool classes. Evaluates usefulness for DKAN contrib and core module development, identifies gaps, and prioritizes improvements.

**Last updated**: 2026-03-17

## Rating System

| Rating | Definition |
|--------|-----------|
| **Strong** | Agent can complete workflows without falling back to source code or Drush |
| **Adequate** | Core needs met but some workflows require supplementary approaches |
| **Gap** | Critical workflows have no tool coverage |

## Per-Category Assessment

| Category | Tools | Contrib | Core | Top Gap |
|----------|-------|---------|------|---------|
| MetastoreTools | 6 | Strong | Adequate | Schema content retrieval (only IDs, not schema JSON), revision management, archive/publish lifecycle |
| DatastoreTools | 6 | Strong | Strong | Enhanced import diagnostics (`ImportInfo`/`ImportInfoList` expose fetcher state, progress %, timestamps — current tool only infers from row count) |
| SearchTools | 1 | Adequate | Adequate | Faceted search (`/api/1/search/facets` endpoint exists, no tool) |
| HarvestTools | 4 | Adequate | **Gap** | No write operations — can't register plans, trigger runs, or revert through MCP. Must use Drush |
| ServiceTools | 3 | Strong | Strong | Minor: `get_service_info` shows only own-class methods; `get_class_info` needed for inherited |
| EventTools | 2 | Strong | Strong | `EVENT_PAYLOAD_TYPES` map covers only 5 of 13+ events. Import/localization events lack payload type info |
| PermissionTools | 3 | Strong | Strong | None |
| ResourceTools | 1 | Strong | Adequate | `findOwningDataset()` iterates ALL datasets — slow on large catalogs. `ReferenceLookup::getReferencers()` would be more efficient |
| WriteTools | 8 | Adequate | **Gap** | No standalone publish/archive. No harvest write ops. No queue processing. No metadata pre-validation |
| DrupalTools | 6 | Strong | Strong | None |
| StatusTools | 2 | Adequate | Adequate | Queue is read-only (counts only). No processing, inspection, or clearing |
| LogTools | 2 | Strong | Strong | None |

**Overall: Strong for contrib development. Adequate for core, with specific gaps in harvest operations, import diagnostics, and lifecycle management.**

## Workflow Composition Assessment

Seven key multi-step workflows that combine tools:

### 1. Build a service that reacts to dataset updates — Strong

`list_events` → `get_event_info` (with dispatch_payload) → `get_service_info` → `get_class_info`. Complete chain from event discovery to implementation.

### 2. Query and analyze datastore data — Strong

`list_datasets` → `list_distributions` (bridge) → `get_datastore_schema` → `get_datastore_stats` → `query_datastore` (with aggregation/joins). Full data exploration loop.

### 3. Debug a failed import — Adequate

`get_import_status` gives basic pass/fail. `get_recent_logs(type: "datastore")` provides errors. `get_queue_status` shows depth. But no fetch progress, parse error details, or timestamps. `ImportInfo`/`ImportInfoList` services would close this.

### 4. Set up and validate a harvest pipeline — Gap

Can read plans/runs but can't register, trigger, or revert. Must fall back to Drush.

### 5. Assess impact of deleting a metadata item — Gap

No `getReferencers()` tool. No pre-validation. `resolve_resource` traces forward only.

### 6. Create test data and validate module behavior — Strong

`create_test_dataset` → `list_distributions` → `import_resource` → `get_import_status` → `query_datastore` → cleanup. Full lifecycle.

### 7. Discover Drupal entity structure for queries — Strong

`list_entity_types` → `get_entity_fields`. Complete.

## Gap Analysis — Prioritized Improvements

### Tier 1 — High Impact

Unlocks new developer workflows.

| Gap | What It Enables | Difficulty | DKAN Service |
|-----|----------------|------------|--------------|
| Schema content retrieval | See required metadata fields, validate datasets before creation | Low | `SchemaRetriever::retrieve()` |
| Enhanced import diagnostics | Fetcher state, progress %, error details, timestamps | Medium | `ImportInfo`, `ImportInfoList` |
| Harvest write operations | Register plans, trigger runs, revert — closed-loop harvest dev | Medium | `HarvestService::registerHarvest()`, `runHarvest()`, `revertHarvest()` |

### Tier 2 — Medium Impact

Fills notable gaps in existing workflows.

| Gap | What It Enables | Difficulty | DKAN Service |
|-----|----------------|------------|--------------|
| Reference impact analysis | "What references this UUID?" for safe deletion | Low | `ReferenceLookup::getReferencers()` |
| Archive/Publish lifecycle | Standalone publish/archive beyond `create_test_dataset` | Low | `MetastoreService::publish()`, `archive()` |
| Complete event payload mapping | Payload types for all 13+ events, not just 5 | Low | Research + constants |
| Metadata pre-validation | Validate JSON against schema before posting | Low-Medium | `ValidMetadataFactory` |

### Tier 3 — Lower Impact

Useful but workarounds exist.

| Gap | What It Enables | Difficulty |
|-----|----------------|------------|
| Faceted search | Discover facet dimensions and values | Low |
| SQL endpoint | Raw SQL for debugging query engine | Low (security risk) |
| Queue processing | Process/clear queue items, not just count | Medium |
| Revision management | List/get/create revisions | Medium |

## Documentation Quality

**Rating: Strong.** Three-layer documentation (README, CLAUDE.md, docs/tools.md) serves different audiences well. The CLAUDE.md agent guide with workflows, parameter reference, and common mistakes table is particularly effective.

## Test Coverage

All 12 tool classes have corresponding unit test files. Tests use standalone stubs (no Drupal bootstrap). 213 tests, 664 assertions.
