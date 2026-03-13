<?php

namespace Drupal\user;

/**
 * Stub for Drupal\user\PermissionHandlerInterface.
 */
interface PermissionHandlerInterface {

  public function getPermissions();

  public function moduleProvidesPermissions($module_name);

}
