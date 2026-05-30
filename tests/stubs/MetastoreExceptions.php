<?php

namespace Drupal\dkan_metastore\Exception;

// Related exception doubles bundled in one file; one-class-per-file does not
// apply to throwaway test stubs.
// phpcs:disable Drupal.Classes.ClassFileName.NoMatch

/**
 * Stubs for MetastoreService exception classes.
 */
class CannotChangeUuidException extends \Exception {}

/**
 * Minimal stub of UnmodifiedObjectException for tests.
 */
class UnmodifiedObjectException extends \Exception {}

/**
 * Minimal stub of MissingObjectException for tests.
 */
class MissingObjectException extends \Exception {}

/**
 * Minimal stub of ExistingObjectException for tests.
 */
class ExistingObjectException extends \Exception {}
