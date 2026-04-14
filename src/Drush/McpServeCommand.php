<?php

namespace Drupal\dkan_mcp\Drush;

use Drupal\dkan_mcp\Server\McpAutoloaderTrait;
use Drupal\dkan_mcp\Server\McpServerFactory;
use Drush\Commands\DrushCommands;
use Mcp\Server\Transport\StdioTransport;

/**
 * Drush command to start the DKAN MCP server over stdio.
 */
class McpServeCommand extends DrushCommands {

  use McpAutoloaderTrait;

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
    $this->loadMcpAutoloader();

    // Suppress any Drupal/Drush output that could corrupt the JSON-RPC stream.
    if (ob_get_level()) {
      ob_end_clean();
    }

    $server = $this->serverFactory->create();
    $transport = new StdioTransport();
    $server->run($transport);
  }

}
