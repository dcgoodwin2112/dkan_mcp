<?php

namespace Drupal\Core\Extension;

/**
 * Stub for Drupal\Core\Extension\ModuleInstallerInterface.
 */
interface ModuleInstallerInterface {

  public function install(array $module_list, $enable_dependencies = TRUE);

  public function uninstall(array $module_list, $uninstall_dependents = TRUE);

}
