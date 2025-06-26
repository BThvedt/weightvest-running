<?php

namespace Drupal\s3fs\Traits;

/**
 * S3fs metadata helper functions.
 *
 * @ingroup s3fs
 */
trait S3fsMetadataHelperTrait {

  /**
   * Evaluate if we should query the bucket for metadata.
   *
   * @param bool $ignore_cache
   *   Is the cache ignored.
   * @param array{'uri'?: string,'filesize'?: string, 'timestamp'?: string, 'dir': '0'|'1', 'version'?: string}|false $metadata
   *   A metadata array or FALSE if no record was present.
   *
   * @return bool
   *   Should a a call be made to lookup metadata in the bucket.
   */
  protected function shouldLookupMetadataFromBucket(bool $ignore_cache, $metadata): bool {
    if (!$ignore_cache) {
      // Never lookup metadata when cache ignore is disabled.
      return FALSE;
    }

    if (!$metadata || !$metadata['dir']) {
      return TRUE;
    }

    return FALSE;
  }

}
