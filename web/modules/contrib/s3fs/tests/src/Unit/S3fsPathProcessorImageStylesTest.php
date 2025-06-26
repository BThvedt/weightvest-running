<?php

namespace Drupal\Tests\s3fs\Unit;

use Drupal\s3fs\PathProcessor\S3fsPathProcessorImageStyles;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * S3 File System ImageStyle Path Processor.
 *
 * Ensure that the remote file system functionality provided by S3 File System
 * isolates the nested folders for schemes.
 *
 * @group s3fs
 *
 * @coversDefaultClass \Drupal\s3fs\PathProcessor\S3fsPathProcessorImageStyles
 */
class S3fsPathProcessorImageStylesTest extends UnitTestCase {

  /**
   * Test PathProcessorInbound().
   *
   * @param string $request_path
   *   Path being requested.
   * @param string $expected_path
   *   Expected result path of PathProcessor.
   * @param \Symfony\Component\HttpFoundation\Request $expected_request
   *   Expected Request() content after processing.
   *
   * @covers ::processInbound
   * @covers ::isImageStylePath
   *
   * @dataProvider providerTestImageStylePathProcessorInbound
   */
  public function testImageStylePathProcessorInbound(string $request_path, string $expected_path, Request $expected_request): void {
    $request = new Request();
    $processor = new S3fsPathProcessorImageStyles();
    $returned_path = $processor->processInbound($request_path, $request);
    $this->assertSame($expected_path, $returned_path);
    $this->assertEquals($expected_request, $request);
  }

  /**
   * Provide data for testImageStylePathProcessorInbound.
   */
  public function providerTestImageStylePathProcessorInbound(): \Generator {
    $request = new Request();
    $request->query->set('file', 'test.jpg');

    yield 's3 scheme ImageStyle path' => [
      'request_path' => '/s3/files/styles/thumbnail/s3/test.jpg',
      'expected_path' => '/s3/files/styles/thumbnail/s3',
      'expected_request' => $request,
    ];

    yield 'public scheme ImageStyle path' => [
      'request_path' => '/s3/files/styles/thumbnail/public/test.jpg',
      'expected_path' => '/s3/files/styles/thumbnail/public',
      'expected_request' => $request,
    ];

    yield 'ReadOnly streamWrapper ImageStyle path' => [
      'request_path' => '/s3/files/styles/thumbnail/readOnly/test.jpg',
      'expected_path' => '/s3/files/styles/thumbnail/readOnly',
      'expected_request' => $request,
    ];

    yield 'Not an S3fs ImageStyle path' => [
      'request_path' => '/not/the/s3/files/styles/thumbnail/s3/test.jpg',
      'expected_path' => '/not/the/s3/files/styles/thumbnail/s3/test.jpg',
      'expected_request' => new Request(),
    ];

  }

}
