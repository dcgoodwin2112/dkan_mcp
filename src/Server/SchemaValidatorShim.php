<?php

/**
 * @file
 * No-op shim that replaces the MCP SDK's SchemaValidator.
 *
 * The SDK's SchemaValidator requires opis/json-schema ^2, which conflicts
 * with DKAN's opis/json-schema ^1. Since both versions share the same
 * namespace and PHP can only load one, we provide this shim that skips
 * JSON schema validation of tool inputs entirely.
 *
 * Tool inputs are already validated by the SDK's type casting in
 * ReferenceHandler and by our tool methods' type hints.
 */

namespace Mcp\Capability\Discovery;

class SchemaValidator {

  public function __construct(
    $logger = NULL,
  ) {}

  /**
   * Skip validation — return no errors.
   */
  public function validateAgainstJsonSchema(mixed $data, array|object $schema): array {
    return [];
  }

}
