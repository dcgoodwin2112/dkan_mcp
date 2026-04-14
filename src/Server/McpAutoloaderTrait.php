<?php

namespace Drupal\dkan_mcp\Server;

use Composer\Autoload\ClassLoader;

/**
 * Loads the MCP SDK autoloader with namespace isolation.
 *
 * The SDK lives in the module's own vendor/ directory to avoid dependency
 * conflicts (opis/json-schema ^2 vs DKAN's ^1). This trait loads the SDK
 * autoloader and filters it to only keep MCP-related namespaces.
 */
trait McpAutoloaderTrait {

  /**
   * Load the MCP SDK autoloader with namespace isolation.
   */
  protected function loadMcpAutoloader(): void {
    // Load the SchemaValidator shim before the MCP SDK autoloader.
    // The SDK's SchemaValidator requires opis/json-schema ^2, which conflicts
    // with DKAN's ^1. Our shim provides a no-op replacement.
    require_once __DIR__ . '/SchemaValidatorShim.php';

    // Load the MCP SDK and its PSR dependencies from the module's vendor.
    // Opis packages have been removed from module vendor (see post-install
    // script in composer.json) to avoid conflicting with DKAN's opis v1.
    $moduleVendor = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($moduleVendor)) {
      return;
    }

    $loader = require_once $moduleVendor;

    // The module's vendor includes packages (Symfony, Doctrine, etc.) at
    // different major versions than the host site. Only keep namespaces
    // the MCP SDK needs that the host site doesn't provide; strip
    // everything else so the host site's autoloader wins.
    if (!$loader instanceof ClassLoader) {
      return;
    }

    $keepPrefixes = [
      'Mcp\\',
      'Psr\\Clock\\',
      'Psr\\Http\\Server\\',
      'Psr\\SimpleCache\\',
      'Revolt\\',
      'Symfony\\Component\\Uid\\',
      'Symfony\\Polyfill\\Uuid\\',
      'phpDocumentor\\Reflection\\',
      'Http\\Discovery\\',
      'Webmozart\\Assert\\',
    ];

    foreach (array_keys($loader->getPrefixesPsr4()) as $prefix) {
      $keep = FALSE;
      foreach ($keepPrefixes as $allowed) {
        if (str_starts_with($prefix, $allowed)) {
          $keep = TRUE;
          break;
        }
      }
      if (!$keep) {
        $loader->setPsr4($prefix, []);
      }
    }

    $classMap = $loader->getClassMap();
    foreach ($classMap as $class => $path) {
      $keep = FALSE;
      foreach ($keepPrefixes as $allowed) {
        if (str_starts_with($class, $allowed)) {
          $keep = TRUE;
          break;
        }
      }
      if (!$keep) {
        unset($classMap[$class]);
      }
    }
    $loader->addClassMap($classMap);
  }

}
