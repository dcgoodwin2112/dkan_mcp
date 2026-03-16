<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Routing\RouteProviderInterface;

/**
 * MCP tools for Drupal runtime introspection.
 */
class DrupalTools {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected ConfigFactoryInterface $configFactory,
    protected RouteProviderInterface $routeProvider,
    protected ContainerInterface $container,
  ) {}

  /**
   * List entity types with bundles.
   */
  public function listEntityTypes(?string $group = NULL): array {
    try {
      $definitions = $this->entityTypeManager->getDefinitions();
      $entityTypes = [];

      foreach ($definitions as $id => $definition) {
        $entityGroup = $definition->getGroup();
        if ($group !== NULL && $entityGroup !== $group) {
          continue;
        }

        $bundles = [];
        $bundleInfo = $this->bundleInfo->getBundleInfo($id);
        foreach ($bundleInfo as $bundleId => $bundleData) {
          $bundles[] = [
            'id' => $bundleId,
            'label' => (string) ($bundleData['label'] ?? $bundleId),
          ];
        }

        $entityTypes[] = [
          'id' => $id,
          'label' => (string) $definition->getLabel(),
          'class' => $definition->getOriginalClass(),
          'group' => $entityGroup,
          'entity_keys' => array_filter($definition->getKeys(), fn($v) => $v !== ''),
          'storage_class' => $definition->getStorageClass(),
          'bundles' => $bundles,
        ];
      }

      return ['entity_types' => $entityTypes, 'total' => count($entityTypes)];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get field definitions for an entity type and optional bundle.
   */
  public function getEntityFields(string $entityTypeId, ?string $bundle = NULL): array {
    try {
      if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
        return ['error' => "Entity type not found: {$entityTypeId}"];
      }

      // Auto-resolve bundle for non-bundleable entities.
      if ($bundle === NULL) {
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        if ($definition->getBundleEntityType() === NULL) {
          $bundle = $entityTypeId;
        }
      }

      // Validate bundle exists.
      if ($bundle !== NULL) {
        $validBundles = $this->bundleInfo->getBundleInfo($entityTypeId);
        if (!isset($validBundles[$bundle])) {
          return ['error' => "Bundle not found: {$bundle} (valid bundles for {$entityTypeId}: " . implode(', ', array_keys($validBundles)) . ')'];
        }
      }

      if ($bundle !== NULL) {
        $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
      }
      else {
        $fieldDefinitions = $this->entityFieldManager->getBaseFieldDefinitions($entityTypeId);
      }

      $fields = [];
      foreach ($fieldDefinitions as $name => $fieldDef) {
        $storageDef = $fieldDef->getFieldStorageDefinition();
        $fields[] = [
          'name' => $name,
          'type' => $fieldDef->getType(),
          'label' => (string) $storageDef->getLabel(),
          'required' => $fieldDef->isRequired(),
          'cardinality' => $storageDef->getCardinality(),
          'description' => (string) $storageDef->getDescription(),
          'is_base_field' => $fieldDef instanceof BaseFieldDefinition,
        ];
      }

      return ['fields' => $fields, 'total' => count($fields)];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * List enabled modules with metadata.
   */
  public function listModules(?string $nameContains = NULL): array {
    try {
      $allInfo = $this->moduleExtensionList->getAllInstalledInfo();
      $modules = [];
      foreach ($this->moduleHandler->getModuleList() as $name => $extension) {
        if ($nameContains !== NULL && !str_contains($name, $nameContains)) {
          continue;
        }
        $info = $allInfo[$name] ?? [];
        $modules[] = [
          'name' => $name,
          'human_name' => $info['name'] ?? $name,
          'version' => $info['version'] ?? NULL,
          'package' => $info['package'] ?? NULL,
          'path' => $extension->getPath(),
          'dependencies' => $info['dependencies'] ?? [],
        ];
      }

      return ['modules' => $modules, 'total' => count($modules)];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get configuration values or list config names by prefix.
   */
  public function getConfig(?string $name = NULL, ?string $prefix = NULL): array {
    try {
      if ($name !== NULL) {
        $config = $this->configFactory->get($name);
        $data = $config->getRawData();
        unset($data['_core']);
        if (empty($data)) {
          return ['error' => "Config not found or empty: {$name}"];
        }
        return ['config_name' => $name, 'data' => $data];
      }

      if ($prefix !== NULL) {
        $names = $this->configFactory->listAll($prefix);
        return ['config_names' => $names, 'total' => count($names)];
      }

      return ['error' => 'Provide either "name" for config values or "prefix" to list config names'];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * List plugin definitions for a plugin type.
   */
  public function listPlugins(string $type): array {
    try {
      $serviceId = "plugin.manager.{$type}";
      if (!$this->container->has($serviceId)) {
        return ['error' => "Plugin manager not found: {$serviceId}"];
      }

      $manager = $this->container->get($serviceId);
      if (!method_exists($manager, 'getDefinitions')) {
        return ['error' => "Service {$serviceId} does not support getDefinitions()"];
      }

      $definitions = $manager->getDefinitions();
      $plugins = [];
      foreach ($definitions as $id => $definition) {
        $plugin = ['id' => $id];

        if (isset($definition['label'])) {
          $plugin['label'] = (string) $definition['label'];
        }
        if (isset($definition['class'])) {
          $plugin['class'] = $definition['class'];
        }
        if (isset($definition['provider'])) {
          $plugin['provider'] = $definition['provider'];
        }
        if (isset($definition['category'])) {
          $plugin['category'] = (string) $definition['category'];
        }

        $plugins[] = $plugin;
      }

      return ['plugins' => $plugins, 'total' => count($plugins)];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get route information by name or path pattern.
   */
  public function getRouteInfo(?string $routeName = NULL, ?string $path = NULL): array {
    try {
      if ($routeName !== NULL) {
        try {
          $route = $this->routeProvider->getRouteByName($routeName);
        }
        catch (\Exception $e) {
          return ['error' => "Route not found: {$routeName}"];
        }
        return ['routes' => [$this->formatRoute($routeName, $route)]];
      }

      if ($path !== NULL) {
        $collection = $this->routeProvider->getRoutesByPattern($path);
        $routes = [];
        foreach ($collection as $name => $route) {
          $routes[] = $this->formatRoute($name, $route);
        }
        return ['routes' => $routes, 'total' => count($routes)];
      }

      return ['error' => 'Provide either "route_name" for exact lookup or "path" for pattern search'];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Format a route object into an array.
   */
  protected function formatRoute(string $name, $route): array {
    $defaults = $route->getDefaults();
    $requirements = $route->getRequirements();
    $options = $route->getOptions();

    // Extract controller.
    $controller = NULL;
    foreach (['_controller', '_form', '_entity_form', '_entity_list'] as $key) {
      if (isset($defaults[$key])) {
        $controller = ['type' => $key, 'value' => $defaults[$key]];
        break;
      }
    }

    // Extract access requirements.
    $access = [];
    foreach (['_permission', '_role', '_access', '_custom_access'] as $key) {
      if (isset($requirements[$key])) {
        $access[$key] = $requirements[$key];
      }
    }

    $result = [
      'name' => $name,
      'path' => $route->getPath(),
      'methods' => $route->getMethods() ?: ['ANY'],
    ];
    if ($controller) {
      $result['controller'] = $controller;
    }
    if ($access) {
      $result['requirements'] = $access;
    }
    if (!empty($options['_admin_route'])) {
      $result['admin_route'] = TRUE;
    }
    if (isset($options['parameters'])) {
      $result['parameters'] = $options['parameters'];
    }

    return $result;
  }

}
