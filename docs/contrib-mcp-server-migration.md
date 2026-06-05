# Migration plan: `dkan_mcp` → `drupal/mcp_server` base

> **Historical snapshot (2026-05-31).** Tool counts below ("35 tools", "12
> writes") reflect the surface at migration time. The current `dkan_mcp_server`
> has since grown to 38 tools (25 read, 13 write); see its `README.md` for the
> authoritative count.

**Date:** 2026-05-31
**Status:** Built & validated in `dkan-site` (2026-05-31) as a new `dkan_mcp_server` module — all 35 tools ported, full surface validated on both transports, `phpcs` clean. Production cutover still gated on the `mcp/sdk 0.6.0` tag. See *Execution record* below; the plan sections that follow are the original design (some decisions changed — noted in the record).
**Companions:** [evaluation](contrib-mcp-server-evaluation.md) · [contributions](contrib-mcp-server-contributions.md)
**Production gate:** do not cut over `dkan-site` until `mcp/sdk 0.6.0` tags (the module pins `dev-main`). Build + verify now; flip later.

## Execution record (2026-05-31)

Built in `dkan-site` as a **new module** `docroot/modules/custom/dkan_mcp_server` (not a rewrite-in-place of `dkan_mcp`; the old module is uninstalled but kept on disk to compare both). Finalized decisions, some of which changed from the plan below:

- **New module** `dkan_mcp_server`; `dkan_mcp` uninstalled (code/tests retained for reference and fallback).
- **Fine-grained permissions** (7) instead of one coarse write permission.
- **`tools/list` is gated too** — `ToolAccessSubscriber` filters the listing via the SDK `ResponseEvent`, in addition to denying `tools/call` via `RequestEvent`. Hidden tools never appear to unprivileged users on either transport.
- **`get_dataset_info`** exposed on all transports (was stdio-only).
- **Explicit one-class-per-tool + per-service base classes** (not a deriver). A deriver was evaluated and rejected: it forces dotted wire names (`prefix.list_datasets`), and the 35 tools are heterogeneous method calls with no uniform interface to collapse, so it would only relocate 35 bespoke bindings into one fat dispatch. Per-service base classes (`MetastoreToolBase`, `WriteToolBase`, …) remove the DI boilerplate that motivated the deriver while keeping clean names and per-tool classes.

Composer (dkan-site root): `mcp/sdk ^0.4 → dev-main`, added `drupal/mcp_server:2.x-dev` (`opis/json-schema` stays `2.6.0`); `config.audit.block-insecure: false` was required for the update to resolve under the advisory pool-exclusion. Backups at `composer.json.premcp.bak` / `composer.lock.premcp.bak`. `dkan_drupal_ai_query` and `dkan_query_tools` untouched.

**Validation** (real DKAN services, both transports): all 35 tools discover/instantiate/enabled; `tools/list` as anon shows 23 reads and hides all 12 writes on stdio *and* HTTP; HTTP `tools/call delete_dataset` → `403 -32003` without permission and reaches `execute()` with it; reads return real data (`get_site_status`, `list_schemas`); `phpcs` clean.

**Tests added:** 40 tests, 212 assertions. **Unit** (`tests/src/Unit`, site PHPUnit) — `ToolAccessSubscriberTest` (deny/allow/defer on `tools/call`; filter/no-mutation/defer on `tools/list`) and `WriteToolPermissionTest` (each of the 12 write tools gates on exactly its one declared permission; reads open; every required permission declared). **Kernel** (`tests/src/Kernel/ToolDiscoveryTest`, core PHPUnit + DDEV test DB) — boots a real container with DKAN + `mcp_server`; all 35 tools discover, instantiate via DI, and default to enabled; the anonymous access matrix (23 reads allowed, 12 writes denied) resolves through the real plugins and permission system. To make the subscriber mockable, it now type-hints `PluginManagerInterface` instead of the final `ToolPluginManager` (same injected service).

**Remaining:** production cutover when `mcp/sdk 0.6.0` tags.

## 1. Goal & scope

Replace `dkan_mcp`'s hand-rolled MCP plumbing with `drupal/mcp_server` as the transport/discovery base, while keeping all DKAN tool logic. Net result: delete the server factory, PSR-11 container, controller, drush serve command, CORS subscriber, and session store; re-expose the same 35 tools as `#[Tool]` plugins; gate writes with a per-tool access subscriber.

**Changes:** the MCP exposure layer only.
**Unchanged:** every line of DKAN/query business logic and its 198 unit tests.

This is a **cutover, not side-by-side**: the old plumbing targets `mcp/sdk ^0.4`; `mcp_server` requires `dev-main` (0.6 API). Both cannot autoload in one site. Build on a branch, flip atomically.

## 2. Architecture: before → after

| Concern | `dkan_mcp` today | After (`mcp_server` base) |
|---|---|---|
| Tool definition | `TOOL_GROUPS` const array → `Builder::addTool()` | one `#[Tool]` plugin class per tool, discovered by `ToolPluginManager` |
| Tool logic | `dkan_query_tools` services + 4 local `Tools/*` classes | **unchanged** — plugins delegate to the same classes |
| DI into tools | `ToolServiceContainer` (PSR-11 shim) | standard plugin `create()` pulling the same services |
| stdio transport | `McpServeCommand` → `dkan-mcp:serve` | `drush mcp:server` (contrib) |
| HTTP transport | `McpController` + `/mcp` route + `FileSessionStore` | contrib `POST /mcp` + contrib session store |
| Read-only HTTP surface | hardcoded 22-tool subset in `HTTP_TOOL_GROUPS` | per-tool access (`checkAccess()` + permission) on both transports |
| Write authorization | none (writes simply absent over HTTP; unguarded over stdio) | `checkAccess()` permission gate enforced by `ToolAccessSubscriber` |
| CORS | `McpCorsSubscriber` | contrib in-module compiler pass (#3583784) |
| SDK isolation | none (already site-level; opis non-conflicting) | none (same) |

## 3. Key decisions (recommended defaults — confirm before build)

1. **Module identity — keep `dkan_mcp` (machine name + repo), rewrite internals.**
   Preserves git history, the `dcgoodwin2112/dkan_mcp` remote, and these docs. The scratch used `dkan_mcp_server` only to avoid colliding during validation. Build the rewrite on a branch; the old `src/Server|Controller|Drush|EventSubscriber` trees are deleted in the cutover commit.

2. **Plugin granularity — one class per tool (35 classes).**
   Each is ~15–25 lines: `#[Tool]` attribute (with explicit schema), `create()` for DI, `execute()` mapping arguments to one method call. Clear, debuggable, matches upstream examples. *Alternative:* a single deriver fed by the existing spec array would collapse boilerplate, but only if `ToolPluginManager` supports `deriver` on attribute discovery (unverified) and it obscures per-tool schemas/access. Defer unless the 35 classes prove painful.

3. **Access model — one coarse write permission to start: `administer dkan via mcp`.**
   All 13 write tools gate on it via `checkAccess()`; the 22 read tools require only `access mcp server` (the base permission). *Finer-grained later* (e.g. `harvest via mcp`, `delete datasets via mcp`, `drop datastore via mcp`) is a config-only follow-up — split the permission, repoint the affected plugins' `checkAccess()`.

4. **`tools/list` visibility — gate listing by access too, not just calls.**
   The validated subscriber gates `CallToolRequest` (deny on call). To preserve today's posture (write tools invisible to unprivileged HTTP clients), also filter `ListToolsRequest` by `checkToolAccess()`. *Minimum* (ship without this): write tools appear in `tools/list` over HTTP but return 403 on call. Recommend doing the list gate — it's the same contract and ~20 lines. This overlaps the upstream contribution (companion doc).

5. **`get_dataset_info` (today stdio-only) — expose everywhere as read-only.**
   `mcp_server` has no transport-based gating, and `drush mcp:server` runs as anonymous by default, so a permission gate wouldn't cleanly reproduce "stdio-only." It is read-only aggregation; exposing it over HTTP is low-risk. Minor, documented behavior change.

6. **Native-tool enablement — ship `defaultConfiguration() ['enabled' => TRUE]` on every plugin.**
   `McpServerFactory`-equivalent instantiates native plugins without saved config, so enablement is code-driven. Interim until the upstream tools-UI work (MR !5) makes it config-driven. Tracked in the contributions doc TODO.

## 4. What stays untouched

- **`dkan_query_tools`** — confirmed framework-neutral (no `dkan_mcp` / `mcp/sdk` references). Its 3 services and 128 unit tests are unaffected. `dkan_drupal_ai_query` (its other consumer) is unaffected.
- **The 4 local logic classes** — `HarvestTools`, `WriteTools`, `ResourceTools`, `StatusTools` (in `dkan_mcp/src/Tools/`). They are already MCP-agnostic plain classes returning arrays; the new plugins delegate to them verbatim. Their **70 unit tests stay green** (they test the classes, not the plumbing).
- **`dkan_mcp.services.yml` service definitions for the 4 logic classes** — reused; the plugins inject them.

## 5. What gets deleted

| Deleted | Tests deleted |
|---|---|
| `src/Server/McpServerFactory.php` | `McpServerFactoryTest` (8) |
| `src/Server/ToolServiceContainer.php` | — |
| `src/Controller/McpController.php` + `dkan_mcp.routing.yml` | `McpControllerTest` (3) |
| `src/Drush/McpServeCommand.php` + `drush.services.yml` | — |
| `src/EventSubscriber/McpCorsSubscriber.php` | — |
| `composer.json` `mcp/sdk` require (moves to root, see §7) | — |

11 plumbing tests removed; the 70 logic tests + 128 `dkan_query_tools` tests remain.

## 6. What's new

- **35 `#[Tool]` plugin classes** under `src/Plugin/Tool/` (one per current tool).
- **`src/EventSubscriber/ToolAccessSubscriber.php`** — the validated subscriber (verbatim from scratch): gates `CallToolRequest` via each plugin's `checkToolAccess()`; throws `McpAuthorizationDeniedException('forbidden', 403)` on deny. Extend to also gate `ListToolsRequest` (decision §3.4).
- **`dkan_mcp.permissions.yml`** — `administer dkan via mcp` (and any finer-grained splits chosen).
- **`dkan_mcp.services.yml`** — register `dkan_mcp.tool_access_subscriber` (args `@plugin.manager.mcp_server.tool`, `@current_user`; tag `event_subscriber`); keep the 4 logic-class services; drop the factory/controller/CORS services.
- **`dkan_mcp.info.yml`** — add `dependencies: - 'mcp_server:mcp_server'`; drop the removed-plumbing implications.

## 7. Composer & dependency cutover

`composer why mcp/sdk` → root only; `dkan_mcp` is a path module absent from the graph. So the cutover touches **root `composer.json`** (the dkan-site repo — currently untouched):

1. Add `"drupal/mcp_server": "2.x-dev"`.
2. Change root `"mcp/sdk": "^0.4"` → `"dev-main"` (→ `"^0.6"` once tagged). Pin both `mcp_server` and `mcp/sdk` to exact commits and freeze until 0.6.0 tags.
3. `composer update drupal/mcp_server mcp/sdk --with-dependencies` → expect `opis/json-schema` to resolve at `2.6.0` (DKAN's lock; validated, no conflict).
4. `ddev drush en mcp_server dkan_mcp -y && ddev drush cr`.

Reversible: revert the root composer change + the `dkan_mcp` branch.

## 8. Tool mapping (35 tools)

Each row = N plugins delegating to the listed source method, at the listed access level. **Schema porting is the bulk of the work:** today only `query_datastore`, `query_datastore_join`, `get_datastore_schema` carry explicit input schemas; the other ~32 auto-generate from method signatures. `#[Tool]` needs an **explicit `inputSchema` for all 35** — derive each from the method signature + docblock. Output schemas are advisory; port the existing ones, omit the rest.

| Group | Tools | Source | Access |
|---|---|---|---|
| Metastore read (8) | `list_datasets`, `get_dataset`, `list_distributions`, `get_distribution`, `list_schemas`, `get_catalog`, `get_schema`, `get_data_dictionary` | `dkan_query_tools.metastore` | `access mcp server` |
| Metastore agg (1) | `get_dataset_info` *(was stdio-only → now everywhere, §3.5)* | `dkan_query_tools.metastore` | `access mcp server` |
| Datastore read (6) | `query_datastore`, `query_datastore_join`, `get_datastore_schema`, `search_columns`, `get_datastore_stats`, `get_import_status` | `dkan_query_tools.datastore` | `access mcp server` |
| Search (1) | `search_datasets` | `dkan_query_tools.search` | `access mcp server` |
| Harvest read (4) | `list_harvest_plans`, `get_harvest_plan`, `get_harvest_runs`, `get_harvest_run_result` | `dkan_mcp.tools.harvest` | `access mcp server` |
| Harvest write (3) | `register_harvest`, `run_harvest`, `deregister_harvest` | `dkan_mcp.tools.harvest` | **`administer dkan via mcp`** |
| Resource read (1) | `resolve_resource` | `dkan_mcp.tools.resource` | `access mcp server` |
| Write (9) | `import_resource`, `update_dataset`, `patch_dataset`, `delete_dataset`, `publish_dataset`, `unpublish_dataset`, `post_metastore_item`, `patch_metastore_item`, `drop_datastore` | `dkan_mcp.tools.write` | **`administer dkan via mcp`** |
| Status read (2) | `get_site_status`, `get_queue_status` | `dkan_mcp.tools.status` | `access mcp server` |

**Attribute flags:** set `readOnly: TRUE` on the 22 reads; `destructive: TRUE` on `delete_dataset`, `drop_datastore`, `deregister_harvest`, `unpublish_dataset`; the remaining writes `readOnly: FALSE, destructive: FALSE`. These annotations are client hints; access is enforced by the subscriber, not the flags.

### Plugin skeleton (representative)

```php
#[Tool(
  id: 'query_datastore',
  label: new TranslatableMarkup('Query datastore'),
  description: new TranslatableMarkup('Query a datastore resource ...'),
  inputSchema: [ /* ported from SCHEMA_QUERY_DATASTORE */ ],
  outputSchema: [ /* ported from OUT_QUERY_RESULT */ ],
  readOnly: TRUE, destructive: FALSE, idempotent: TRUE, openWorld: FALSE,
)]
final class QueryDatastoreTool extends ToolPluginBase {
  public static function create(ContainerInterface $c, array $cfg, $id, $def): static {
    $i = parent::create($c, $cfg, $id, $def);
    $i->datastore = $c->get('dkan_query_tools.datastore');
    return $i;
  }
  protected function defaultConfiguration(): array { return ['enabled' => TRUE]; }
  public function execute(array $a, ClientGateway $g): mixed {
    return $this->datastore->queryDatastore(
      $a['resourceId'], $a['columns'] ?? NULL, $a['conditions'] ?? NULL,
      $a['sortField'] ?? NULL, $a['sortDirection'] ?? 'asc',
      (int) ($a['limit'] ?? 100), (int) ($a['offset'] ?? 0),
      $a['expressions'] ?? NULL, $a['groupings'] ?? NULL,
    );
  }
}
```

Write plugins add `checkAccess(AccountInterface $a): AccessResultInterface` returning `AccessResult::allowedIfHasPermission($a, 'administer dkan via mcp')`.

## 9. Transport parity checklist

- **stdio** — `drush mcp:server` replaces `dkan-mcp:serve`. Update README + any client `mcp.json` snippets (command + args). Note the stdio per-call-denial wrinkle (eval §"Remaining gate"): a denied write kills the loop. Low impact (stdio is local/trusted) — keep writes ungated for stdio in practice by running the server as a privileged user, or accept the wrinkle pending the upstream stdio fix (contributions doc, Contribution 2).
- **HTTP** — `POST /mcp` (contrib route; note the path is `/mcp`, same as today). Verify the base permission `access mcp server` gates the endpoint.
- **CORS** — drop `McpCorsSubscriber`; confirm contrib's compiler-pass CORS emits the headers our browser clients need (`Mcp-Session-Id` expose, `Mcp-Protocol-Version` allow). If contrib's set is narrower, file/patch upstream or re-add a thin response subscriber.
- **Sessions** — drop `FileSessionStore`; confirm contrib's session handling persists `Mcp-Session-Id` across requests.

## 10. Phased execution (with gates)

**Phase 0 — confirm decisions (§3).** Module name, permission granularity, list-gating, `get_dataset_info`. No code.

**Phase 1 — scaffold on a branch.** Branch `dkan_mcp`. Add `mcp_server` dep to info.yml; add `ToolAccessSubscriber` + permissions + the 4 logic-class services (kept). Port 3–4 representative tools first: one metastore read, `query_datastore` (explicit schema), one write (`delete_dataset`), one harvest. *Gate:* `drush mcp:server` lists them; read works; write denied as anon, allowed with permission; `POST /mcp` 200/403 parity. (Mirrors the scratch validation.)

**Phase 2 — port the remaining 31 tools.** Mechanical: one plugin each, explicit input schema each. *Gate:* `tools/list` shows 35; spot-check one tool per source service end-to-end.

**Phase 3 — delete old plumbing (§5).** Remove Server/Controller/Drush/EventSubscriber trees, routing.yml, drush.services.yml, the 11 plumbing tests, and the module `composer.json` `mcp/sdk` require. *Gate:* `phpcs` clean; 70 logic + 128 query tests green; no dangling references.

**Phase 4 — docs + clients.** README/ARCHITECTURE/AGENTS: new command, route, permissions, access model. Update client config snippets.

**Phase 5 — production cutover (gated on `mcp/sdk 0.6.0` tag).** Root composer change (§7) + enable + `cr`. Run the acceptance checklist (§12) against `dkan-site`.

## 11. Testing strategy

- **Keep:** 128 `dkan_query_tools` + 70 logic-class tests — unchanged, prove the behavior the plugins delegate to.
- **Delete:** 11 plumbing tests (factory/controller) — their subjects are gone.
- **Add:** (a) ✅ done — `ToolAccessSubscriberTest` + `WriteToolPermissionTest` (37 tests): pure unit, mocked plugin manager + real SDK events, no Drupal bootstrap (own `tests/bootstrap.php` registers the contrib/custom namespaces over the site autoloader); (b) ✅ done — `ToolDiscoveryTest` (kernel, 3 tests): boots `mcp_server` + `dkan_mcp_server` + DKAN, asserts all 35 discover/instantiate/enabled and the anonymous access matrix (23 read-allow / 12 write-deny) through the real plugins. Runs under core PHPUnit with the DDEV test DB. The plugin layer is thin enough that exhaustive per-plugin tests aren't needed — the logic is already covered.
- **Eval suite:** unaffected (`dkan_drupal_ai_query` doesn't touch MCP).

## 12. Acceptance checklist (run at cutover)

- [ ] `composer update` resolves; `opis/json-schema` = `2.6.0`.
- [ ] `drush mcp:server`: `initialize` + `tools/list` returns 35 tools with schemas/annotations.
- [ ] Read tool over stdio + HTTP returns structured output identical to pre-migration shape.
- [ ] Write tool denied without `administer dkan via mcp` (HTTP 403 `-32003`); succeeds with it.
- [ ] `tools/list` over HTTP for an unprivileged user excludes write tools (if list-gating shipped, §3.4).
- [ ] CORS headers present on `/mcp` for a browser client.
- [ ] `phpcs` clean; 198 retained tests green.
- [ ] `dkan_drupal_ai_query` still functions (sanity).

## 13. Risks & rollback

| Risk | Mitigation |
|---|---|
| `mcp/sdk dev-main` BC break before 0.6.0 tags | pin exact commits; don't cut over production until tagged |
| Schema-porting errors across 32 tools | port + spot-check per group; the input shapes are simple (strings/ints) |
| stdio denial kills loop | run stdio as privileged user, or land upstream Contribution 2 |
| Lost stdio-only scoping for `get_dataset_info` | accepted (read-only); documented |
| CORS/session parity gap in contrib | verify in Phase 1; thin re-add if needed |

**Rollback:** revert the root `composer.json` change and the `dkan_mcp` branch; `composer update` + `drush cr` restores the old module. Because the cutover is one composer change + one branch, rollback is atomic.

## 14. Effort

- **Mechanical bulk:** 35 thin plugins + 35 input schemas (~32 newly written). Low complexity, high count.
- **Net deletion:** the entire Server/Controller/Drush/CORS/PSR-11 layer.
- **Proven:** dependency resolution, both transports, and per-tool write-gating are already validated in `~/Sites/mcp-scratch`; this plan scales that pattern from 2 tools to 35.
- **Critical-path dependency:** the `mcp/sdk 0.6.0` tag for production. Phases 1–4 can complete before it lands.
