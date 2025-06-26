<?php

namespace Drupal\Tests\s3fs\Functional;

use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\s3fs\Exceptions\CrossSchemeAccessException;
use Drupal\s3fs\StreamWrapper\S3fsStream;

/**
 * S3 File System Scheme Isolation Tests.
 *
 * Ensure that the remote file system functionality provided by S3 File System
 * isolates the nested folders for schemes.
 *
 * @group s3fs
 */
class S3fsStreamIsolationTest extends S3fsTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['s3fs'];

  /**
   * Coverage test for the stream wrapper.
   *
   * @param string $path
   *   Path to test against.
   * @param bool $expect_exception
   *   Should this path trigger an exception.
   * @param array $new_config
   *   S3fs config to override from defaults.
   *
   * @dataProvider schemaDirectoryExceptionsDataProvider
   */
  public function testSchemaExceptionsForFiles(string $path, bool $expect_exception, array $new_config = []) {

    // Enable public/private takeover.
    $settings['settings']['s3fs.use_s3_for_public'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $settings['settings']['s3fs.use_s3_for_private'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    if (!empty($new_config)) {
      $config = $this->config('s3fs.settings');
      foreach ($new_config as $key => $item) {
        $config->set($key, $item);
      }
      $this->prepareConfig($config);
      drupal_static_reset();
    }

    $this->rebuildAll();

    // Prevent 'scheme://' from becoming 'scheme:/'.
    if (StreamWrapperManager::getTarget($path)) {
      $path = rtrim($path, '/');
    }

    $path = $path . '/test.txt';

    /** @var \Drupal\s3fs\S3fsFileService|\Drupal\s3fs\S3fsFileSystemD103 $file_system */
    $file_system = $this->container->get('file_system');

    try {
      // Use write mode so that the s3client doesn't error on object not found
      // for read.
      $file = fopen($path, 'w');
      fclose($file);
      $this->assertFalse($expect_exception, 'no exception on fopen');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "fopen exception test");
    }

    try {
      $file_system->saveData('file_system saveData (putObject) test', $path);
      $this->assertFalse($expect_exception, 'no exception on file_system saveData');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "file_system saveData threw exception");
    }

    try {
      $s3fs_stream = new S3fsStream();
      $s3fs_stream->setUri($path);
      $this->assertFalse($expect_exception, 'no exception on setUri');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "setUri threw exception");
    }

    try {
      unlink($path);
      $this->assertFalse($expect_exception, 'no exception on unlink');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "unlink threw exception");
    }

    try {
      $scheme = StreamWrapperManager::getScheme($path);
      $source_file = $scheme . '://rename_source.txt';
      file_put_contents($source_file, 'source file for rename test');
      rename($source_file, $path);
      $this->assertFalse($expect_exception, 'no exception on rename');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "rename threw exception");
    }

    try {
      $scheme = StreamWrapperManager::getScheme($path);
      $source_file = $scheme . '://rename_source.txt';
      file_put_contents($source_file, 'source file for rename test');
      $file_system->move($source_file, $path);
      $this->assertFalse($expect_exception, 'no exception on file_system move');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "file_system move threw exception");
    }

    try {
      stat($path);
      $this->assertFalse($expect_exception, 'no exception on stat');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "stat threw exception");
    }

    try {
      $s3fs_stream = new S3fsStream();
      $s3fs_stream->writeUriToCache($path);
      $this->assertFalse($expect_exception, 'no exception on writeUriToCache');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "writeUriToCache threw exception");
    }

  }

  /**
   * Test directory functions for access exception.
   *
   * @param string $path
   *   Path to test against.
   * @param bool $expect_exception
   *   Should this path trigger an exception.
   * @param array $new_config
   *   S3fs config to override from defaults.
   *
   * @dataProvider schemaDirectoryExceptionsDataProvider
   */
  public function testDirectoriesAccess(string $path, bool $expect_exception, array $new_config = []) {

    if (!empty($new_config)) {
      $config = $this->config('s3fs.settings');
      foreach ($new_config as $key => $item) {
        $config->set($key, $item);
      }
      $this->prepareConfig($config);
      drupal_static_reset();
    }

    $s3fs_stream = new S3fsStream();
    try {
      $s3fs_stream->mkdir($path, 0755, 0);
      $this->assertFalse($expect_exception, 'no exception on mkdir');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "mkdir threw exception");
    }

    try {
      $s3fs_stream->rmdir($path, 0);
      $this->assertFalse($expect_exception, 'no exception on rmdir');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "rmdir threw exception");
    }

    try {
      // We don't care that the dir doesn't exist so suppress errors.
      @$s3fs_stream->url_stat($path, 0);
      $this->assertFalse($expect_exception, 'no exception on stat');
    }
    catch (CrossSchemeAccessException $e) {
      $this->assertTrue($expect_exception, "stat threw exception");
    }

  }

  /**
   * Provide data for testDirectoriesAccess.
   *
   * This is intended to just be a functional validation that
   * S3fsPathsTrait::preventCrossSchemeAccess() is called to block access.
   *
   * The S3fsPathsTraitsTest does the more in depth scenarios.
   *
   * @return array[]
   *   - Path
   *   - Should throw exception
   *   - array config to override.
   */
  public static function schemaDirectoryExceptionsDataProvider() {
    return [
      'Allow s3:// Root level access' => [
        's3://',
        FALSE,
      ],
      'Deny public:// via s3://' => [
        's3://s3fs-public',
        TRUE,
      ],
      'Deny directory under public:// via s3://' => [
        's3://s3fs-public/somdir',
        TRUE,
      ],
      'Allow Directory carrying same name as public://' => [
        's3://subdir/s3fs-public/somdir',
        FALSE,
      ],
      'validate s3://s3fs-public/ does not block when renamed' => [
        's3://s3fs-public/somdir',
        FALSE,
        ['public_folder' => 'test1'],
      ],
      'validate s3://renamed-public/ does block when renamed' => [
        's3://renamed-public/somdir',
        TRUE,
        ['public_folder' => 'renamed-public'],
      ],
    ];
  }

  /**
   * Validate that file listings are not exposed through scandir().
   */
  public function testDirectoryScan() {
    $values = [
      [
        'uri' => 's3://testdir',
        'filesize' => '0',
        'timestamp' => '150000000',
        'dir' => '1',
        'version' => '',
      ],
      [
        'uri' => 's3://testdir/s3fs-private',
        'filesize' => '0',
        'timestamp' => '150000000',
        'dir' => '1',
        'version' => '',
      ],
      [
        'uri' => 's3://testdir/s3fs-public',
        'filesize' => '0',
        'timestamp' => '150000000',
        'dir' => '1',
        'version' => '',
      ],
      [
        'uri' => 's3://s3fs-public',
        'filesize' => '0',
        'timestamp' => '150000000',
        'dir' => '1',
        'version' => '',
      ],
      [
        'uri' => 's3://s3fs-public/test.txt',
        'filesize' => '100',
        'timestamp' => '150000000',
        'dir' => '0',
        'version' => '',
      ],
      [
        'uri' => 's3://s3fs-private',
        'filesize' => '0',
        'timestamp' => '150000000',
        'dir' => '1',
        'version' => '',
      ],
      [
        'uri' => 's3://s3fs-private/test.txt',
        'filesize' => '100',
        'timestamp' => '150000000',
        'dir' => '0',
        'version' => '',
      ],
    ];

    $query = $this->connection
      ->insert('s3fs_file')
      ->fields(['uri', 'filesize', 'timestamp', 'dir', 'version']);
    foreach ($values as $record) {
      $query->values($record);
    }
    $query->execute();

    $scan_root = scandir("s3://");
    $this->assertCount(1, $scan_root);
    $scan_testdir = scandir("s3://testdir");
    $this->assertCount(2, $scan_testdir);

    try {
      scandir('s3://s3fs-public/');
      $this->fail('Expected exception for s3://s3fs-public');
    }
    catch (CrossSchemeAccessException $e) {
    }
    catch (\Exception $e) {
      $this->fail('Did not throw CrossSchemeAccessException for s3://s3fs-public');
    }

    try {
      scandir('s3://s3fs-private/');
      $this->fail('Expected exception for s3://s3fs-private');
    }
    catch (CrossSchemeAccessException $e) {
    }
    catch (\Exception $e) {
      $this->fail('Did not throw CrossSchemeAccessException for s3://s3fs-private');
    }

  }

}
