<?php

namespace Drupal\Tests\s3fs\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\s3fs\Exceptions\CrossSchemeAccessException;
use Drupal\s3fs\Traits\S3fsPathsTrait;
use Drupal\Tests\UnitTestCase;

/**
 * S3 File System Scheme Isolation Tests.
 *
 * Ensure that the remote file system functionality provided by S3 File System
 * isolates the nested folders for schemes.
 *
 * @group s3fs
 *
 * @coversDefaultClass \Drupal\s3fs\Traits\S3fsPathsTrait
 */
class S3fsPathsTraitsTest extends UnitTestCase {

  use S3fsPathsTrait;

  /**
   * Coverage test for the preventCrossSchemeAccess().
   *
   * @param string $directory_path
   *   Directory path to test against.
   * @param bool $expect_exception
   *   Should this path trigger an exception.
   * @param array $new_config
   *   S3fs config to override from defaults.
   *
   * @dataProvider schemaDirectoryExceptionsDataProvider
   *
   * @covers ::preventCrossSchemeAccess
   */
  public function testCrossSchemeAccessException(string $directory_path, bool $expect_exception, array $new_config = []) {

    // Stream_wrapper_manager service mock that assumes scheme is always valid.
    $stream_wrapper_manager_mock = $this->createMock(StreamWrapperManagerInterface::class);
    $stream_wrapper_manager_mock->method('normalizeUri')->willReturnCallback(
      function ($uri) {
        $scheme = StreamWrapperManager::getScheme($uri);

        $target = StreamWrapperManager::getTarget($uri);

        if ($target !== FALSE) {
          $uri = $scheme . '://' . $target;
        }
        return $uri;
      }
    );

    // Mock s3fs.settings.
    $s3fs_config = [];
    if (!empty($new_config)) {
      foreach ($new_config as $key => $item) {
        $s3fs_config[$key] = $item;
      }
    }
    $config_factory_mock = $this->getConfigFactoryStub([
      's3fs.settings' => $s3fs_config,
    ]);

    // Set mock services into global container.
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('config.factory', $config_factory_mock);
    $container->set('stream_wrapper_manager', $stream_wrapper_manager_mock);

    // Prevent 'scheme://test.txt' from becoming
    // 'scheme:/test.txt' or scheme:///test.txt.
    if (StreamWrapperManager::getTarget($directory_path)) {
      $directory_path = rtrim($directory_path, '/') . '/';

    }
    $file_path = $directory_path . 'test.txt';

    // Test the directory path.
    try {
      $this->preventCrossSchemeAccess($directory_path);
      $this->assertFalse($expect_exception, 'no exception from preventCrossSchemeAccess for directory');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "exception thrown from preventCrossSchemeAccess for directory");
    }

    // Test the path with a file under directory.
    try {
      $this->preventCrossSchemeAccess($file_path);
      $this->assertFalse($expect_exception, 'no exception from preventCrossSchemeAccess for file under directory');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "exception thrown from preventCrossSchemeAccess for file under directory");
    }

  }

  /**
   * Provide data for testDirectoriesAccess.
   *
   * @return array[]
   *   - Path
   *   - Should throw exception
   *   - array config to override.
   */
  public static function schemaDirectoryExceptionsDataProvider() {
    return [
      'Allow s3:// root level access' => [
        's3://',
        FALSE,
      ],
      'Allow public:// root level' => [
        'public://',
        FALSE,
      ],
      'Allow private:// root level' => [
        'private://',
        FALSE,
      ],
      'Deny public:// under s3://' => [
        's3://s3fs-public',
        TRUE,
      ],
      'Deny private:// under s3://' => [
        's3://s3fs-private',
        TRUE,
      ],
      'Deny public://something under s3://' => [
        's3://s3fs-public/something',
        TRUE,
      ],
      'Deny private://something under s3://' => [
        's3://s3fs-private/something',
        TRUE,
      ],
      'Allow s3:// sub-path contains public:// prefix' => [
        's3://sub-path/s3fs-public',
        FALSE,
      ],
      'Allow s3:// sub-path contains private:// prefix' => [
        's3://sub-path/s3fs-private',
        FALSE,
      ],
      'Allow s3:// directory carrying same prefix as public://' => [
        's3://s3fs-public-sub_directory',
        FALSE,
      ],
      'Allow s3:// directory carrying same prefix as private://' => [
        's3://s3fs-private-sub_directory',
        FALSE,
      ],
      's3://s3fs-public does not block when prefix renamed' => [
        's3://s3fs-public',
        FALSE,
        ['public_folder' => 'test1'],
      ],
      's3://s3fs-private does not block when prefix renamed' => [
        's3://s3fs-private',
        FALSE,
        ['private_folder' => 'test1'],
      ],
      's3://renamed-public does block with renamed prefix' => [
        's3://renamed-public',
        TRUE,
        ['public_folder' => 'renamed-public'],
      ],
      's3://renamed-private does block with renamed prefix' => [
        's3://renamed-private',
        TRUE,
        ['private_folder' => 'renamed-private'],
      ],
      'Deny private:// under public://' => [
        'public://s3fs-private',
        TRUE,
        [
          'private_folder' => 'test1/s3fs-private',
          'public_folder' => 'test1',
        ],
      ],
      'Deny private://something under public://' => [
        'public://s3fs-private/something',
        TRUE,
        [
          'private_folder' => 'test1/s3fs-private',
          'public_folder' => 'test1',
        ],
      ],
      'Deny public:// under private://'  => [
        'private://s3fs-public',
        TRUE,
        [
          'private_folder' => 'test1',
          'public_folder' => 'test1/s3fs-public',
        ],
      ],
      'Deny public://something under private://'  => [
        'private://s3fs-public/something',
        TRUE,
        [
          'private_folder' => 'test1',
          'public_folder' => 'test1/s3fs-public',
        ],
      ],
      'Allow public:// with path that partially matches nested private:// prefix'  => [
        'private://s3fs-public-partial-match',
        FALSE,
        [
          'private_folder' => 'test1',
          'public_folder' => 'test1/s3fs-public',
        ],
      ],
      'Allow private:// with path that partially matches nested public:// prefix'  => [
        'private://s3fs-private-partial-match',
        FALSE,
        [
          'private_folder' => 'test1/s3fs-private',
          'public_folder' => 'test1',
        ],
      ],
    ];
  }

}
