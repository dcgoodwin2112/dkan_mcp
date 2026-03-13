<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\user\PermissionHandlerInterface;

/**
 * MCP tools for DKAN permission introspection.
 */
class PermissionTools {

  /**
   * Cached route-to-permission map.
   */
  protected ?array $routePermissionMap = NULL;

  public function __construct(
    protected PermissionHandlerInterface $permissionHandler,
    protected RouteProviderInterface $routeProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * List DKAN permissions with metadata.
   */
  public function listPermissions(?string $module = NULL): array {
    $dkanPerms = $this->getDkanPermissions();

    if ($module) {
      $dkanPerms = array_filter(
        $dkanPerms,
        fn($perm) => $perm['provider'] === $module
      );
    }

    ksort($dkanPerms);

    $permissions = [];
    foreach ($dkanPerms as $name => $info) {
      $permissions[] = [
        'name' => $name,
        'title' => (string) ($info['title'] ?? ''),
        'description' => (string) ($info['description'] ?? ''),
        'provider' => $info['provider'] ?? '',
        'restrict_access' => !empty($info['restrict access']),
      ];
    }

    return ['permissions' => $permissions, 'total' => count($permissions)];
  }

  /**
   * Get details for a permission: definition, routes, and roles.
   */
  public function getPermissionInfo(string $permission): array {
    $allPerms = $this->permissionHandler->getPermissions();
    if (!isset($allPerms[$permission])) {
      return ['error' => "Permission not found: {$permission}"];
    }

    $info = $allPerms[$permission];
    $definition = [
      'title' => (string) ($info['title'] ?? ''),
      'description' => (string) ($info['description'] ?? ''),
      'provider' => $info['provider'] ?? '',
      'restrict_access' => !empty($info['restrict access']),
    ];

    // Find routes requiring this permission.
    $routeMap = $this->buildRoutePermissionMap();
    $routes = [];
    foreach ($routeMap as $routeName => $routeInfo) {
      $parsedPerms = $this->parsePermissionExpression($routeInfo['permission_expression']);
      if (in_array($permission, $parsedPerms, TRUE)) {
        $routes[] = [
          'route_name' => $routeName,
          'path' => $routeInfo['path'],
          'methods' => $routeInfo['methods'],
          'permission_expression' => $routeInfo['permission_expression'],
        ];
      }
    }

    // Find roles with this permission.
    $roles = [];
    try {
      $roleEntities = $this->entityTypeManager
        ->getStorage('user_role')
        ->loadMultiple();
      foreach ($roleEntities as $role) {
        if ($role->hasPermission($permission)) {
          $roles[] = [
            'id' => $role->id(),
            'label' => $role->label(),
          ];
        }
      }
    }
    catch (\Exception) {
      // Role storage unavailable.
    }

    return [
      'permission' => $permission,
      'definition' => $definition,
      'routes' => $routes,
      'roles' => $roles,
    ];
  }

  /**
   * Detect permission misconfigurations.
   */
  public function checkPermissions(): array {
    $dkanPerms = $this->getDkanPermissions();
    $dkanPermNames = array_keys($dkanPerms);
    $allPerms = $this->permissionHandler->getPermissions();
    $routeMap = $this->buildRoutePermissionMap();

    // Track which DKAN permissions are used in routes.
    $usedInRoutes = [];

    // Orphaned route permissions: in DKAN routes but not defined.
    $orphanedRoutePerms = [];
    foreach ($routeMap as $routeName => $routeInfo) {
      if (!$this->isDkanRoute($routeName, $routeInfo['path'])) {
        continue;
      }
      $parsedPerms = $this->parsePermissionExpression($routeInfo['permission_expression']);
      foreach ($parsedPerms as $perm) {
        if (in_array($perm, $dkanPermNames, TRUE)) {
          $usedInRoutes[$perm] = TRUE;
        }
        if (!isset($allPerms[$perm])) {
          $orphanedRoutePerms[] = [
            'permission' => $perm,
            'route_name' => $routeName,
            'path' => $routeInfo['path'],
          ];
        }
      }
    }

    // Also scan non-DKAN routes for DKAN permission usage.
    foreach ($routeMap as $routeName => $routeInfo) {
      $parsedPerms = $this->parsePermissionExpression($routeInfo['permission_expression']);
      foreach ($parsedPerms as $perm) {
        if (in_array($perm, $dkanPermNames, TRUE)) {
          $usedInRoutes[$perm] = TRUE;
        }
      }
    }

    // Unused permissions: defined but not in any route.
    $unusedPerms = [];
    foreach ($dkanPermNames as $perm) {
      if (!isset($usedInRoutes[$perm])) {
        $note = $this->isEntityAccessPermission($perm)
          ? 'Entity access permission (no route usage expected)'
          : NULL;
        $entry = [
          'permission' => $perm,
          'provider' => $dkanPerms[$perm]['provider'] ?? '',
        ];
        if ($note) {
          $entry['note'] = $note;
        }
        $unusedPerms[] = $entry;
      }
    }

    // Orphaned role permissions: roles with DKAN permissions not defined.
    $orphanedRolePerms = [];
    try {
      $roleEntities = $this->entityTypeManager
        ->getStorage('user_role')
        ->loadMultiple();
      foreach ($roleEntities as $role) {
        foreach ($role->getPermissions() as $perm) {
          if (in_array($perm, $dkanPermNames, TRUE) && !isset($allPerms[$perm])) {
            $orphanedRolePerms[] = [
              'permission' => $perm,
              'role_id' => $role->id(),
              'role_label' => $role->label(),
            ];
          }
        }
      }
    }
    catch (\Exception) {
      // Role storage unavailable.
    }

    $totalIssues = count($orphanedRoutePerms)
      + count(array_filter($unusedPerms, fn($p) => !isset($p['note'])))
      + count($orphanedRolePerms);

    return [
      'orphaned_route_permissions' => $orphanedRoutePerms,
      'unused_permissions' => $unusedPerms,
      'orphaned_role_permissions' => $orphanedRolePerms,
      'summary' => ['total_issues' => $totalIssues],
    ];
  }

  /**
   * Get DKAN module names by checking module paths.
   */
  protected function getDkanModules(): array {
    $dkanModules = [];
    foreach ($this->moduleHandler->getModuleList() as $name => $extension) {
      if ($name === 'dkan' || str_contains($extension->getPath(), 'modules/contrib/dkan/')) {
        $dkanModules[] = $name;
      }
    }
    return $dkanModules;
  }

  /**
   * Get permissions provided by DKAN modules.
   */
  protected function getDkanPermissions(): array {
    $dkanModules = $this->getDkanModules();
    $allPerms = $this->permissionHandler->getPermissions();

    return array_filter(
      $allPerms,
      fn($perm) => in_array($perm['provider'] ?? '', $dkanModules, TRUE)
    );
  }

  /**
   * Build a map of route name → permission info from all routes.
   */
  protected function buildRoutePermissionMap(): array {
    if ($this->routePermissionMap !== NULL) {
      return $this->routePermissionMap;
    }

    $this->routePermissionMap = [];
    foreach ($this->routeProvider->getAllRoutes() as $routeName => $route) {
      $permission = $route->getRequirement('_permission');
      if ($permission === NULL) {
        continue;
      }
      $this->routePermissionMap[$routeName] = [
        'path' => $route->getPath(),
        'methods' => $route->getMethods() ?: ['ANY'],
        'permission_expression' => $permission,
      ];
    }

    return $this->routePermissionMap;
  }

  /**
   * Parse a permission expression into individual permission names.
   */
  protected function parsePermissionExpression(string $expression): array {
    return array_map('trim', preg_split('/[+,]/', $expression));
  }

  /**
   * Check if a route is DKAN-related.
   */
  protected function isDkanRoute(string $routeName, string $path): bool {
    $prefixes = ['dkan', 'metastore', 'datastore', 'harvest', 'dkan_alt_api'];
    foreach ($prefixes as $prefix) {
      if (str_starts_with($routeName, $prefix)) {
        return TRUE;
      }
    }
    return str_starts_with($path, '/api/1/');
  }

  /**
   * Check if a permission name matches entity access patterns.
   */
  protected function isEntityAccessPermission(string $permission): bool {
    $entityActions = ['administer', 'view', 'edit', 'delete', 'create'];
    foreach ($entityActions as $action) {
      if (str_starts_with($permission, $action . ' ')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
