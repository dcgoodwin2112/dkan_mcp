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
      require_once $moduleVendor;
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
