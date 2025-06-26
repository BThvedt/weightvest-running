<?php

namespace Drupal\Tests\s3fs\Kernel;

use Composer\Semver\Semver;
use Drupal\KernelTests\Core\File\FileTestBase;

/**
 * Tests filename mimetype detection.
 *
 * @group File
 */
class S3fsMimeTypeTest extends FileTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file_test', 's3fs'];

  /**
   * Tests mapping of mimetypes from filenames.
   */
  public function testFileMimeTypeDetection(): void {
    $uses_new_guesser = Semver::satisfies(\Drupal::VERSION, '>=9.1');
    $core_guesser = $this->container->get('file.mime_type.guesser');
    $s3fs_guesser = $this->container->get('s3fs.mime_type.guesser');

    $test_case = [
      'test.jar',
      'test.jpeg',
      'test.JPEG',
      'test.jpg',
      'test.jar.jpg',
      'test.jpg.jar',
      'test.pcf.Z',
      'pcf.z',
      'jar',
      'some.junk',
      'foo.file_test_1',
      'foo.file_test_2',
      'foo.doc',
      'test.ogg',
    ];

    // Test using default mappings.
    foreach ($test_case as $filename) {
      if ($uses_new_guesser) {
        $this->assertTrue(method_exists($s3fs_guesser, 'guessMimeType'));
        $this->assertTrue(method_exists($core_guesser, 'guessMimeType'));
        $s3fs_output = $s3fs_guesser->guessMimeType('public://' . $filename);
        $core_output = $core_guesser->guessMimeType('public://' . $filename);
      }
      else {
        $this->assertTrue(method_exists($s3fs_guesser, 'guess'));
        $this->assertTrue(method_exists($core_guesser, 'guess'));
        $s3fs_output = $s3fs_guesser->guess('public://' . $filename);
        $core_output = $core_guesser->guess('public://' . $filename);
      }
      $this->assertSame($core_output, $s3fs_output, "public://$filename matches core");

      if ($uses_new_guesser) {
        $this->assertTrue(method_exists($s3fs_guesser, 'guessMimeType'));
        $this->assertTrue(method_exists($core_guesser, 'guessMimeType'));
        $s3fs_output = $s3fs_guesser->guessMimeType('$filename');
        $core_output = $core_guesser->guessMimeType('$filename');
      }
      else {
        $this->assertTrue(method_exists($s3fs_guesser, 'guess'));
        $this->assertTrue(method_exists($core_guesser, 'guess'));
        $s3fs_output = $s3fs_guesser->guess('$filename');
        $core_output = $core_guesser->guess('$filename');
      }
      $this->assertSame($core_output, $s3fs_output, "$filename matches core");
    }

    // Now test the extension guesser by passing in a custom mapping.
    $mapping = [
      'mimetypes' => [
        0 => 's3fs/test-type',
      ],
      'extensions' => [
        's3fs' => 0,
      ],
    ];

    $s3fs_extension_guesser = $this->container->get('s3fs.mime_type.guesser.extension');
    $s3fs_extension_guesser->setMapping($mapping);
    if ($uses_new_guesser) {
      $this->assertTrue(method_exists($s3fs_extension_guesser, 'guessMimeType'));
      $this->assertSame('s3fs/test-type', $s3fs_extension_guesser->guessMimeType('test.s3fs'));
    }
    else {
      $this->assertTrue(method_exists($s3fs_extension_guesser, 'guess'));
      $this->assertSame('s3fs/test-type', $s3fs_extension_guesser->guess('test.s3fs'));
    }
  }

}
