<?php

namespace Drupal\Core\Extension;

/**
 * Stub for Drupal\Core\Extension\ModuleHandlerInterface.
 */
interface ModuleHandlerInterface {

  /**
   * Module exists.
   */
  public function moduleExists($module);

  /**
   * Get module list.
   */
  public function getModuleList();

}
