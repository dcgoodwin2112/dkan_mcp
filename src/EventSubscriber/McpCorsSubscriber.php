<?php

namespace Drupal\dkan_mcp\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds CORS headers to MCP endpoint responses.
 *
 * Drupal's OptionsRequestSubscriber intercepts OPTIONS requests before the
 * controller runs, so CORS headers from the SDK transport are never set on
 * preflight responses. This subscriber adds them to all /mcp responses.
 */
class McpCorsSubscriber implements EventSubscriberInterface {

  /**
   * CORS headers required by the MCP Streamable HTTP transport.
   */
  private const CORS_HEADERS = [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'POST, DELETE, OPTIONS',
    'Access-Control-Allow-Headers' => 'Accept,Authorization,Content-Type,Last-Event-ID,Mcp-Protocol-Version,Mcp-Session-Id',
    'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
  ];

  /**
   * Add CORS headers to /mcp responses.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if ($request->getPathInfo() !== '/mcp') {
      return;
    }

    $response = $event->getResponse();
    foreach (self::CORS_HEADERS as $name => $value) {
      if (!$response->headers->has($name)) {
        $response->headers->set($name, $value);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after Drupal's response handling.
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

}
