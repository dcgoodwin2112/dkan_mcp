<?php

namespace Drupal\Core\Extension;

/**
 * Stub for Drupal\Core\Extension\ModuleHandlerInterface.
 */
interface ModuleHandlerInterface {

  public function moduleExists($module);

  public function getModuleList();

}
