<?php

namespace Drupal\dkan_mcp\Drush;

use Drupal\dkan_mcp\Server\McpServerFactory;
use Drush\Commands\DrushCommands;

/**
 * Drush command to start the DKAN MCP server over stdio.
 */
class McpServeCommand extends DrushCommands {

  public function __construct(
    protected McpServerFactory $serverFactory,
  ) {
    parent::__construct();
  }

  /**
   * Start the DKAN MCP server (stdio transport).
   *
   * @command dkan-mcp:serve
   * @aliases dkan-mcp
   */
  public function serve(): void {
    // Load the SchemaValidator shim before the MCP SDK autoloader.
    // The SDK's SchemaValidator requires opis/json-schema ^2, which conflicts
    // with DKAN's ^1. Our shim provides a no-op replacement.
    require_once dirname(__DIR__) . '/Server/SchemaValidatorShim.php';

    // Load the MCP SDK and its PSR dependencies from the module's vendor.
    // Opis packages have been removed from module vendor (see post-install
    // script in composer.json) to avoid conflicting with DKAN's opis v1.
    $moduleVendor = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (file_exists($moduleVendor)) {
      $loader = require_once $moduleVendor;
      // The module's vendor includes packages (Symfony, Doctrine, etc.) at
      // different major versions than the host site. Only keep namespaces
      // the MCP SDK needs that the host site doesn't provide; strip
      // everything else so the host site's autoloader wins.
      if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $keepPrefixes = [
          'Mcp\\',
          'Psr\\Clock\\',
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

    // Suppress any Drupal/Drush output that could corrupt the JSON-RPC stream.
    if (ob_get_level()) {
      ob_end_clean();
    }

    $server = $this->serverFactory->create();
    $transport = new \Mcp\Server\Transport\StdioTransport();
    $server->run($transport);
  }

}
