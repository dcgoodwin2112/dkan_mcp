<?php

namespace RootedData;

/**
 * Stub for RootedData\RootedJsonData.
 */
class RootedJsonData {

  /**
   * The backing JSON string.
   *
   * @var string
   */
  protected string $json;

  public function __construct(string $json = '{}', $schema = '{}') {
    $this->json = $json;
  }

  /**
   * Returns the JSON string.
   */
  public function __toString(): string {
    return $this->json;
  }

  /**
   * Set.
   */
  public function set(string $path, $value): void {
    if ($path === '$') {
      $this->json = json_encode($value);
    }
  }

}
