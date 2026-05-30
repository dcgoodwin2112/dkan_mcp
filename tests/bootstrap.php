<?php

/**
 * @file
 * Bootstrap for PHPUnit tests.
 *
 * The module has no vendor directory of its own (the MCP SDK is a site-level
 * dependency), so tests run under the site-level PHPUnit. This bootstrap
 * registers PSR-4 autoloading for the module's own classes and loads
 * lightweight stubs for the DKAN/Drupal services the tool classes depend on.
 */

spl_autoload_register(static function (string $class): void {
  $prefixes = [
    'Drupal\\dkan_mcp\\' => __DIR__ . '/../src/',
    'Drupal\\Tests\\dkan_mcp\\' => __DIR__ . '/src/',
  ];
  foreach ($prefixes as $prefix => $baseDir) {
    if (str_starts_with($class, $prefix)) {
      $relative = substr($class, strlen($prefix));
      $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
      if (is_file($file)) {
        require $file;
      }
      return;
    }
  }
});

// Load stubs for external DKAN/Drupal classes not autoloadable in this context.
foreach (glob(__DIR__ . '/stubs/*.php') as $stub) {
  require_once $stub;
}
