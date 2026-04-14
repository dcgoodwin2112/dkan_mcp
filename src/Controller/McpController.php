<?php

namespace Drupal\dkan_mcp\Controller;

use Drupal\dkan_mcp\Server\McpAutoloaderTrait;
use Drupal\dkan_mcp\Server\McpServerFactory;
use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP endpoint for the MCP server.
 *
 * Exposes a read-only subset of DKAN tools via the MCP Streamable HTTP
 * transport at /mcp. Accepts JSON-RPC 2.0 requests over POST.
 */
class McpController {

  use McpAutoloaderTrait;

  /**
   * Tool groups exposed over HTTP (read-only, data consumer-focused).
   */
  private const HTTP_TOOL_GROUPS = [
    'metastore',
    'datastore',
    'search',
    'harvest_read',
    'resource',
    'status',
  ];

  public function __construct(
    protected McpServerFactory $serverFactory,
  ) {}

  /**
   * Handle an MCP HTTP request.
   */
  public function handle(Request $request): Response {
    $this->loadMcpAutoloader();

    // Convert Symfony Request to PSR-7.
    $psr17Factory = new HttpFactory();
    $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
    $psrRequest = $psrHttpFactory->createRequest($request);

    // Use file-based sessions to persist state across HTTP requests.
    $sessionDir = sys_get_temp_dir() . '/dkan_mcp_sessions';
    $sessionStore = new FileSessionStore($sessionDir);

    // Create MCP server with HTTP tool subset and run with HTTP transport.
    $server = $this->serverFactory->create(self::HTTP_TOOL_GROUPS, $sessionStore);
    $transport = new StreamableHttpTransport(
      request: $psrRequest,
      responseFactory: $psr17Factory,
      streamFactory: $psr17Factory,
    );

    /** @var \Psr\Http\Message\ResponseInterface $psrResponse */
    $psrResponse = $server->run($transport);

    // Convert PSR-7 Response back to Symfony.
    $httpFoundationFactory = new HttpFoundationFactory();
    return $httpFoundationFactory->createResponse($psrResponse);
  }

}
