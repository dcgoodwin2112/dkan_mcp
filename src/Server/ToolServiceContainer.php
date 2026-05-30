<?php

namespace Drupal\dkan_mcp\Server;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Minimal PSR-11 container mapping tool-class FQCNs to their DI instances.
 *
 * The MCP SDK resolves array handlers ([Class::class, 'method']) to a method
 * call on an instance obtained from the configured container (see
 * Mcp\Capability\Registry\ReferenceHandler::getClassInstance). Without a
 * container it would fall back to `new Class()`, which fails for our
 * service-injected tool classes. This wrapper hands back the instances the
 * factory already received from Drupal's DI.
 */
class ToolServiceContainer implements ContainerInterface {

  /**
   * Constructs a ToolServiceContainer.
   *
   * @param array<string, object> $services
   *   Tool-class FQCN => instance.
   */
  public function __construct(
    protected array $services,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $id): object {
    if (!isset($this->services[$id])) {
      throw new class("Service '$id' is not registered.") extends \RuntimeException implements NotFoundExceptionInterface {};
    }
    return $this->services[$id];
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $id): bool {
    return isset($this->services[$id]);
  }

}
