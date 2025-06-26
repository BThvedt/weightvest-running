<?php

namespace Drupal\s3fs_test_read_only_wrapper\StreamWrapper;

use Drupal\Core\StreamWrapper\LocalReadOnlyStream;

/**
 * Helper class for testing non-s3fs streamWrapper compatibility.
 *
 * Dummy stream wrapper implementation (dummy-read-only://).
 *
 * Originally copied from
 * Drupal\Core\StreamWrapper\LocalReadOnlyStream\DummyReadOnlyStreamWrapper
 */
class ReadOnlyStreamWrapper extends LocalReadOnlyStream {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'Dummy files (readonly)';
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return 'Dummy wrapper for testing (readonly).';
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath(): string {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $site_path = \Drupal::getContainer()->getParameter('site.path');
    if (!is_string($site_path)) {
      $site_path = '/tmp';
    }
    return $site_path . '/files';
  }

  /**
   * Override getInternalUri().
   *
   * Return a dummy path for testing.
   */
  public function getInternalUri(): string {
    return '/dummy/example.txt';
  }

  /**
   * Override getExternalUrl().
   *
   * Return the HTML URI of a public file.
   */
  public function getExternalUrl(): string {
    return '/dummy/example.txt';
  }

}
