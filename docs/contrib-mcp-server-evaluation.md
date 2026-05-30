# Decision: do not build on `drupal/mcp_server` (yet)

**Date:** 2026-05-30
**Status:** Decided — stay on the raw `mcp/sdk` foundation `dkan_mcp` already uses.
**Versions evaluated:** `drupal/mcp_server` `dev-2.x` @ `4fa0c8d`; `mcp/sdk` `v0.5.0` and `dev-main` @ `0347dc8`; DKAN `4.x-dev`.

## Summary

We evaluated the contrib [`drupal/mcp_server`](https://www.drupal.org/project/mcp_server) module as a foundation for exposing DKAN over MCP. It is a strong long-term target but is **not buildable against any released `mcp/sdk` today**: its `dev-2.x` HEAD references an SDK interface that exists in no public SDK version. We continue on `dkan_mcp`'s direct-`mcp/sdk` approach, which works on this site.

## What works (verified)

Not a wholesale rejection — the architecture is sound:

- **No opis conflict.** DKAN 4.x and `mcp/sdk` both require `opis/json-schema ^2.4` (site lock 2.6.0). The module-vendoring workaround `dkan_mcp` carries (see [AGENTS.md](../AGENTS.md)) is unnecessary on DKAN 4.x.
- **Both transports respond.** HTTP `/_mcp` (anonymous reachable once the role has `access mcp server`; creates a session; `initialize`/`tools/list` succeed) and stdio (`drush mcp:server`).
- **Auth model is clean.** Base route gates on the `access mcp server` permission + cookie auth. OAuth 2.1 lives in the `mcp_server_oauth` submodule (per-tool `required`/`disabled`, scopes as third-party settings). Per-call authorization is the SDK's `RequestEvent`.
- **Tool contract is ergonomic.** `#[Tool(id, label, description, inputSchema, outputSchema, readOnly/destructive/idempotent/openWorld)]` on a `ToolPluginBase` subclass; handler is `execute(array $arguments, ClientGateway $gateway): mixed`; exposure gated by `isEnabled()` (config `enabled`).

## Why not now (blocker)

`drupal/mcp_server` `dev-2.x` is mid-migration to an unreleased `mcp/sdk` API.

1. **References a nonexistent interface.** `ToolPluginInterface` extends `Mcp\Server\Handler\RuntimeToolHandlerInterface`, which exists in **no** public `mcp/sdk` commit (checked all branches and full history).
2. **Released `v0.5.0` silently fails.** The interface is missing, so every tool plugin class fails to load. Drupal's plugin discovery skips unloadable classes **without error**, so `tools/list` returns `[]` and the failure is invisible — the server appears healthy but exposes nothing.
3. **`dev-main` is incompatible too.** The SDK renamed the interface to `ToolHandlerInterface` **and** changed `Builder::addTool()` to require `callable|array|string` instead of a handler object. A one-line rename patch restores discovery (confirmed: example tools `echo` + `content_lookup` appear), but `tools/call` then fatals on the `addTool` signature — fixing it requires further contrib patches against a moving target.
4. **The module's own constraint is stale.** `composer.json` declares `mcp/sdk: ^0.5.0`, which does not match what HEAD actually needs. The module's `AGENTS.md` states it is *"still in active development and has not been released yet."* There are no tagged `2.x` releases.

### Root cause

The official `mcp/sdk` (Symfony + PHP Foundation) is **experimental until 1.0** and breaks backward compatibility between pre-releases — the interface rename and `addTool` change are SDK BC breaks, not contrib defects. Building on contrib means tracking **two** moving pre-release dependencies (the module's `dev-2.x` branch *and* the SDK). `dkan_mcp` instead pins **one** dependency (`mcp/sdk`) to a tag it controls, and works today.

## Decision

Continue developing `dkan_mcp` on `mcp/sdk` directly, pinned to a known-good tag. Revisit `drupal/mcp_server` when the preconditions below are met.

## Revisit / migration plan

### Preconditions to reopen this decision

- `drupal/mcp_server` publishes a **tagged stable release** (not a `dev` branch).
- That release pins a **released `mcp/sdk`** version (a tag, not `dev-main`), and that SDK version is compatible with DKAN's `opis/json-schema ^2.4`.
- The `mcp_server_oauth` submodule covers our authenticated-write requirements.

### What a migration would look like

The bulk of `dkan_mcp`'s value — the DKAN-aware tool logic — is reusable. The plumbing `dkan_mcp` hand-rolls would be replaced by contrib equivalents.

| `dkan_mcp` today | Maps to in `mcp_server` | Notes |
|---|---|---|
| Tool classes (`src/Tools/*`, shared `dkan_query_tools` classes) | `#[Tool]` plugins in `src/Plugin/Tool/` extending `ToolPluginBase` | Reuse the DKAN service calls + response shaping; rewrite the wrapper. One plugin per tool (or derivers for schema-generic metastore items). |
| `McpServerFactory` tool registration / subsetting | `ToolPluginManager` discovery + per-tool `enabled` config | Drop the manual registry; map the read-only HTTP subset to per-tool auth config. |
| `McpServeCommand` (stdio) + `McpController` (HTTP) | `drush mcp:server` + `/_mcp` | Delete; contrib owns both transports. |
| `McpAutoloaderTrait` + `SchemaValidatorShim` + module-vendored SDK + `post-install-cleanup` | (removed) | Contrib loads `mcp/sdk` at site level; opis v2 is fine on DKAN 4.x. Eliminates the entire isolation workaround. |
| Ad-hoc / no transport auth | `mcp_server_oauth` (OAuth 2.1) + `checkToolAccess()` + `RequestEvent` | Read-only tools: `enabled` + auth `disabled`, grant anonymous `access mcp server`. Write tools: OAuth scope + Drupal permission. |
| `input`/`output` schemas (current shapes) | `#[Tool]` `inputSchema` / `outputSchema` JSON Schema | Port existing schemas into the attribute. |

### Effort and risk

- **Mechanical, low-risk:** tool logic, schemas, response shapes carry over. Net deletion of transport/autoloader/shim code.
- **New work:** OAuth consumer/scope setup; per-tool auth config entities; converting the read-only subset to config.
- **Risk:** until contrib tags a stable release, any migration inherits its SDK-tracking churn. Do not start until the preconditions hold.

## Evidence

- Module source: `docroot/modules/contrib/mcp_server` (`dev-2.x` @ `4fa0c8d`) and its `AGENTS.md`.
- `Mcp\Server\Handler\` in `mcp/sdk dev-main` provides `ToolHandlerInterface` / `ElementHandlerInterface`; no `RuntimeToolHandlerInterface` in any branch.
- `Mcp\Server\Builder::addTool()` `dev-main` signature: `(callable|array|string $handler, ...)`.
- Observed `TypeError`: `addTool(): Argument #1 ($handler) must be of type callable|array|string, Drupal\mcp_server_examples\Plugin\Tool\ContentLookupTool given`.
