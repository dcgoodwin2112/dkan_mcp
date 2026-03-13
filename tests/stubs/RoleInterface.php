<?php

namespace Drupal\user;

/**
 * Stub for Drupal\user\RoleInterface.
 */
interface RoleInterface {

  public function id();

  public function label();

  public function hasPermission($permission);

  public function getPermissions();

}
