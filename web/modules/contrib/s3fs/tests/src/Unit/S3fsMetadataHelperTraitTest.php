<?php

namespace Drupal\Tests\s3fs\Unit;

use Drupal\s3fs\Traits\S3fsMetadataHelperTrait;
use Drupal\Tests\UnitTestCase;

/**
 * S3 File System Metadata Helper Trait tests.
 *
 * @group s3fs
 *
 * @coversDefaultClass \Drupal\s3fs\Traits\S3fsMetadataHelperTrait
 */
class S3fsMetadataHelperTraitTest extends UnitTestCase {

  use S3fsMetadataHelperTrait;

  /**
   * Test shouldLookupMetadataFromBucket()
   *
   * @param bool $expected_result
   *   Expected result.
   * @param bool $is_cache_ignored
   *   Is cache ignored.
   * @param array{'dir': "0"|"1"}|false $metadata
   *   Sample array metadata.
   *
   * @dataProvider providerShouldLookupMetadataFromBucket
   */
  public function testShouldLookupMetadataFromBucket(bool $expected_result, bool $is_cache_ignored, $metadata): void {
    $this->assertSame($expected_result, $this->shouldLookupMetadataFromBucket($is_cache_ignored, $metadata));
  }

  /**
   * Provider for testShouldLookupMetadataFromBucket().
   */
  public static function providerShouldLookupMetadataFromBucket(): \Generator {
    yield 'Cache not ignored, no records exists' => [
      FALSE,
      FALSE,
      FALSE,
    ];

    yield 'Cache not ignored, record is a file' => [
      FALSE,
      FALSE,
      ['dir' => '0'],
    ];

    yield 'Cache not ignored, record is a directory' => [
      FALSE,
      FALSE,
      ['dir' => '1'],
    ];

    yield 'Cache ignored, no records exists' => [
      TRUE,
      TRUE,
      FALSE,
    ];

    yield 'Cache ignored, record is a file' => [
      TRUE,
      TRUE,
      ['dir' => '0'],
    ];

    yield 'Cache ignored, record is a directory' => [
      FALSE,
      TRUE,
      ['dir' => '1'],
    ];

  }

}
