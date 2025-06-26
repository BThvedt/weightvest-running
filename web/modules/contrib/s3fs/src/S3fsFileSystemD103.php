<?php

namespace Drupal\s3fs;

use Aws\Exception\AwsException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\DirectoryNotReadyException;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\Exception\FileNotExistsException;
use Drupal\Core\File\Exception\FileWriteException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Utility\Error;
use Drupal\s3fs\Traits\S3fsPathsTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Provides helpers to operate on files and stream wrappers.
 *
 * PHP convenience functions copy(),rename(), move_uploaded_file(), etc do not
 * check that the write buffer is successfully flushed. As such we need to
 * handle the writes ourself so we can return when an error.
 *
 * Additionally by calling putObject and copyObject we avoid the
 * StreamWrapper creating a buffer copy of the source file in.
 *
 * @see https://www.drupal.org/project/s3fs/issues/2972161
 * @see https://www.drupal.org/project/s3fs/issues/3204635
 *
 * @requires php >= 8.1
 * @requires drupal/core >= 10.3
 *
 * @internal
 */
final class S3fsFileSystemD103 implements FileSystemInterface {

  use S3fsPathsTrait;

  /**
   * S3fsFileService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $decorated
   *   FileSystem Service being decorated.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   StreamWrapper manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   File logging service.
   * @param \Drupal\s3fs\S3fsServiceInterface $s3fs
   *   S3fs Service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config Factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module Handler service.
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mimeGuesser
   *   Mime type guesser service.
   * @param \Psr\Log\LoggerInterface $s3fsLogger
   *   S3fs logging channel.
   */
  public function __construct(
    protected FileSystemInterface $decorated,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected LoggerInterface $logger,
    protected S3fsServiceInterface $s3fs,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected MimeTypeGuesserInterface $mimeGuesser,
    protected LoggerInterface $s3fsLogger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function moveUploadedFile($filename, $uri) {
    $wrapper = $this->streamWrapperManager->getViaUri($uri);
    if (!is_bool($wrapper) && is_a($wrapper, 'Drupal\s3fs\StreamWrapper\S3fsStream')) {
      // Ensure the file was uploaded as part of HTTP POST.
      if (!is_uploaded_file($filename)) {
        return FALSE;
      }
      return $this->putObject($filename, $uri);
    }
    else {
      return $this->decorated->moveUploadedFile($filename, $uri);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chmod($uri, $mode = NULL) {
    return $this->decorated->chmod($uri, $mode);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($uri, $context = NULL) {
    return $this->decorated->unlink($uri, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function realpath($uri) {
    return $this->decorated->realpath($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri) {
    return $this->decorated->dirname($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function basename($uri, $suffix = NULL) {
    return $this->decorated->basename($uri, $suffix);
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($uri, $mode = NULL, $recursive = FALSE, $context = NULL) {
    return $this->decorated->mkdir($uri, $mode, $recursive, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($uri, $context = NULL) {
    return $this->decorated->rmdir($uri, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function tempnam($directory, $prefix) {
    return $this->decorated->tempnam($directory, $prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function copy($source, $destination, /* FileExists */$fileExists = FileExists::Rename) {
    $fileExists = $this->convertFileExists($fileExists);

    $wrapper = $this->streamWrapperManager->getViaUri($destination);
    if (!is_bool($wrapper) && is_a($wrapper, 'Drupal\s3fs\StreamWrapper\S3fsStream')) {

      $this->prepareDestination($source, $destination, $fileExists);
      $srcScheme = $this->streamWrapperManager->getScheme($source);
      $dstScheme = $this->streamWrapperManager->getScheme($destination);

      if ($srcScheme == $dstScheme) {
        $result = $this->copyObject($source, $destination);
      }
      else {
        $result = $this->putObject($source, $destination);
      }

      if (!$result) {
        $this->logger->error("The specified file '%source' could not be copied to '%destination'.",
          [
            '%source' => $source,
            '%destination' => $destination,
          ]);
        throw new FileWriteException("The specified file '$source' could not be copied to '$destination'.");
      }

      return $destination;
    }
    else {
      return $this->decorated->copy($source, $destination, $fileExists);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path) {
    return $this->decorated->delete($path);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRecursive($path, ?callable $callback = NULL) {
    return $this->decorated->deleteRecursive($path, $callback);
  }

  /**
   * {@inheritdoc}
   */
  public function move($source, $destination, $fileExists = FileExists::Rename) {
    $fileExists = $this->convertFileExists($fileExists);

    $wrapper = $this->streamWrapperManager->getViaUri($destination);
    if (!is_bool($wrapper) && is_a($wrapper, 'Drupal\s3fs\StreamWrapper\S3fsStream')) {
      $this->prepareDestination($source, $destination, $fileExists);

      // Ensure compatibility with Windows.
      // @see \Drupal\Core\File\FileSystemInterface::unlink().
      if (!$this->streamWrapperManager->isValidUri($source) && (substr(PHP_OS, 0, 3) == 'WIN')) {
        chmod($source, 0600);
      }

      // Attempt to resolve the URIs. This is necessary in certain
      // configurations (see above) and can also permit fast moves across local
      // schemes.
      $real_source = $this->realpath($source) ?: $source;

      $srcScheme = $this->streamWrapperManager->getScheme($real_source);
      $dstScheme = $this->streamWrapperManager->getScheme($destination);

      if ($srcScheme == $dstScheme) {
        $result = $this->copyObject($real_source, $destination);
      }
      else {
        // Both sources are not on the same StreamWrapper.
        // Fall back to slow copy and unlink procedure.
        $result = $this->putObject($real_source, $destination);
      }

      if (!$result) {
        $this->logger->error("The specified file '%source' could not be moved to '%destination'.", [
          '%source' => $source,
          '%destination' => $destination,
        ]);
        throw new FileWriteException("The specified file '$source' could not be moved to '$destination'.");
      }
      else {
        if (!@unlink($real_source)) {
          $this->logger->error("The source file '%source' could not be unlinked after copying to '%destination'.", [
            '%source' => $source,
            '%destination' => $destination,
          ]);
          throw new FileException("The source file '$source' could not be unlinked after copying to '$destination'.");
        }
      }

      return $destination;
    }
    else {
      return $this->decorated->move($source, $destination, $fileExists);
    }
  }

  /**
   * Prepares the destination for a file copy or move operation.
   *
   * - Checks if $source and $destination are valid and readable/writable.
   * - Checks that $source is not equal to $destination; if they are an error
   *   is reported.
   * - If file already exists in $destination either the call will error out,
   *   replace the file or rename the file based on the $replace parameter.
   *
   * @param string $source
   *   A string specifying the filepath or URI of the source file.
   * @param string|null $destination
   *   A URI containing the destination that $source should be moved/copied to.
   *   The URI may be a bare filepath (without a scheme) and in that case the
   *   default scheme (file://) will be used.
   * @param \Drupal\Core\File\FileExists $fileExists
   *   Replace behavior when the destination file already exists:
   *   - FileSystemInterface::EXISTS_REPLACE - Replace the existing file.
   *   - FileSystemInterface::EXISTS_RENAME - Append _{incrementing number}
   *     until the filename is unique.
   *   - FileSystemInterface::EXISTS_ERROR - Do nothing and return FALSE.
   *
   * @see \Drupal\Core\File\FileSystemInterface::copy()
   * @see \Drupal\Core\File\FileSystemInterface::move()
   */
  private function prepareDestination($source, &$destination, FileExists $fileExists): void {
    $original_source = $source;

    if (!file_exists($source)) {
      if (($realpath = $this->realpath($original_source)) !== FALSE) {
        $this->logger->error("File '%original_source' ('%realpath') could not be copied because it does not exist.", [
          '%original_source' => $original_source,
          '%realpath' => $realpath,
        ]);
        throw new FileNotExistsException("File '$original_source' ('$realpath') could not be copied because it does not exist.");
      }
      else {
        $this->logger->error("File '%original_source' could not be copied because it does not exist.", [
          '%original_source' => $original_source,
        ]);
        throw new FileNotExistsException("File '$original_source' could not be copied because it does not exist.");
      }
    }

    // Prepare the destination directory.
    if ($this->prepareDirectory($destination)) {
      // The destination is already a directory, so append the source basename.
      $destination = $this->streamWrapperManager->normalizeUri($destination . '/' . $this->basename($source));
    }
    else {
      // Perhaps $destination is a dir/file?
      $dirname = $this->dirname($destination);
      if (!$this->prepareDirectory($dirname)) {
        $this->logger->error("The specified file '%original_source' could not be copied because the destination directory '%destination_directory' is not properly configured. This may be caused by a problem with file or directory permissions.", [
          '%original_source' => $original_source,
          '%destination_directory' => $dirname,
        ]);
        throw new DirectoryNotReadyException("The specified file '$original_source' could not be copied because the destination directory '$dirname' is not properly configured. This may be caused by a problem with file or directory permissions.");
      }
    }

    // Determine whether we can perform this operation based on overwrite rules.
    $destination = $this->getDestinationFilename($destination, $fileExists);
    if (is_bool($destination)) {
      $this->logger->error("File '%original_source' could not be copied because a file by that name already exists in the destination directory ('%destination').", [
        '%original_source' => $original_source,
        '%destination' => $destination,
      ]);
      throw new FileExistsException("File '$original_source' could not be copied because a file by that name already exists in the destination directory ('$destination').");
    }

    // Assert that the source and destination filenames are not the same.
    $real_source = $this->realpath($source);
    $real_destination = $this->realpath($destination);
    if ($source == $destination || ($real_source !== FALSE) && ($real_source == $real_destination)) {
      $this->logger->error("File '%source' could not be copied because it would overwrite itself.", [
        '%source' => $source,
      ]);
      throw new FileException("File '$source' could not be copied because it would overwrite itself.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveData($data, $destination, /* FileExists */$fileExists = FileExists::Rename) {
    $fileExists = $this->convertFileExists($fileExists);

    // Write the data to a temporary file.
    $temp_name = $this->tempnam('temporary://', 'file');
    if (is_bool($temp_name) || file_put_contents($temp_name, $data) === FALSE) {
      $this->logger->error("Temporary file '%temp_name' could not be created.", ['%temp_name' => $temp_name]);
      throw new FileWriteException("Temporary file '$temp_name' could not be created.");
    }

    // Move the file to its final destination.
    return $this->move($temp_name, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDirectory(&$directory, $options = self::MODIFY_PERMISSIONS) {
    return $this->decorated->prepareDirectory($directory, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getDestinationFilename($destination, /* FileExists */$fileExists) {
    $fileExists = $this->convertFileExists($fileExists);
    return $this->decorated->getDestinationFilename($destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function createFilename($basename, $directory) {
    return $this->decorated->createFilename($basename, $directory);
  }

  /**
   * {@inheritdoc}
   */
  public function getTempDirectory() {
    return $this->decorated->getTempDirectory();
  }

  /**
   * {@inheritdoc}
   */
  public function scanDirectory($dir, $mask, array $options = []) {
    return $this->decorated->scanDirectory($dir, $mask, $options);
  }

  /**
   * Upload a file that is not in the bucket.
   *
   * @param string $source
   *   Source file to be copied.
   * @param string $destination
   *   Destination path in bucket.
   *
   * @return bool
   *   True if successful, else FALSE.
   */
  protected function putObject($source, $destination) {
    // We only need to convert relative path for storing on the bucket.
    $destination = $this->resolvePath($destination);

    $this->preventCrossSchemeAccess($destination);

    if (mb_strlen($destination) > S3fsServiceInterface::MAX_URI_LENGTH) {
      $this->logger->error("The specified file '%destination' exceeds max URI length limit.",
        [
          '%destination' => $destination,
        ]);
      return FALSE;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $destination)) {
      $this->logger->error("The specified file '%destination' contains UTF8 4-byte characters.",
        [
          '%destination' => $destination,
        ]);
      return FALSE;
    }
    $config = $this->configFactory->get('s3fs.settings')->get();
    assert(is_array($config));
    $wrapper = $this->streamWrapperManager->getViaUri($destination);
    if (is_bool($wrapper) || !is_a($wrapper, 'Drupal\s3fs\StreamWrapper\S3fsStream')) {
      return FALSE;
    }

    $scheme = $this->streamWrapperManager->getScheme($destination);
    $key_path = $this->streamWrapperManager->getTarget($destination);
    if (is_bool($key_path)) {
      return FALSE;
    }

    if ($scheme === 'public') {
      $target_folder = !empty($config['public_folder']) ? $config['public_folder'] . '/' : 's3fs-public/';
      $key_path = $target_folder . $key_path;
    }
    elseif ($scheme === 'private') {
      $target_folder = !empty($config['private_folder']) ? $config['private_folder'] . '/' : 's3fs-private/';
      $key_path = $target_folder . $key_path;
    }

    if (!empty($config['root_folder'])) {
      $key_path = $config['root_folder'] . '/' . $key_path;
    }

    $contentType = $this->mimeGuesser->guessMimeType($key_path);

    $uploadParams = [
      'Bucket' => $config['bucket'],
      'Key' => $key_path,
      'SourceFile' => $source,
      'ContentType' => $contentType,
    ];

    if (!empty($config['encryption'])) {
      $uploadParams['ServerSideEncryption'] = $config['encryption'];
    }

    // Set the Cache-Control header, if the user specified one.
    if (!empty($config['cache_control_header'])) {
      $uploadParams['CacheControl'] = $config['cache_control_header'];
    }

    $uploadAsPrivate = Settings::get('s3fs.upload_as_private');

    if ($scheme !== 'private' && !$uploadAsPrivate) {
      $uploadParams['ACL'] = 'public-read';
    }

    $this->moduleHandler->alter('s3fs_upload_params', $uploadParams);

    try {
      $s3 = $this->s3fs->getAmazonS3Client($config);
    }
    catch (S3fsException $e) {
      $exception_variables = Error::decodeException($e);
      $this->s3fsLogger->error('AmazonS3Client error: @message', $exception_variables);
      return FALSE;
    }

    try {
      $s3->putObject($uploadParams);
    }
    catch (AwsException $e) {
      $exception_variables = Error::decodeException($e);
      // In some cases the getAwsErrorMessage() method is returns an empty
      // string. Like when we try to use a nonexistent bucket.
      if (!empty($e->getAwsErrorMessage())) {
        $exception_variables['message'] = $e->getAwsErrorMessage();
      }
      $exception_variables['@request_id'] = $e->getAwsRequestId();
      $this->s3fsLogger->error('An error occurred when uploading a file: @message. Request ID: @request_id', $exception_variables);
      return FALSE;
    }
    catch (\Exception $e) {
      $exception_variables = Error::decodeException($e);
      $this->s3fsLogger->error('An error occurred when uploading a file: @message.', $exception_variables);
      return FALSE;
    }

    $wrapper->writeUriToCache($destination);
    return TRUE;
  }

  /**
   * Copy a file that is already in the the bucket.
   *
   * @param string $source
   *   Source file to be copied.
   * @param string $destination
   *   Destination path in bucket.
   *
   * @return bool
   *   True if successful, else FALSE.
   */
  protected function copyObject($source, $destination) {
    $source = $this->resolvePath($source);
    $destination = $this->resolvePath($destination);

    $this->preventCrossSchemeAccess($source);
    $this->preventCrossSchemeAccess($destination);

    if (mb_strlen($destination) > S3fsServiceInterface::MAX_URI_LENGTH) {
      $this->logger->error("The specified file '%destination' exceeds max URI length limit.",
        [
          '%destination' => $destination,
        ]);
      return FALSE;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $destination)) {
      $this->logger->error("The specified file '%destination' contains UTF8 4-byte characters.",
        [
          '%destination' => $destination,
        ]);
      return FALSE;
    }

    $config = $this->configFactory->get('s3fs.settings')->get();
    assert(is_array($config));

    $wrapper = $this->streamWrapperManager->getViaUri($destination);
    if (is_bool($wrapper) || !is_a($wrapper, 'Drupal\s3fs\StreamWrapper\S3fsStream')) {
      return FALSE;
    }

    $scheme = $this->streamWrapperManager->getScheme($destination);
    $key_path = $this->streamWrapperManager->getTarget($destination);
    if (is_bool($key_path)) {
      return FALSE;
    }
    $src_key_path = $this->streamWrapperManager->getTarget($source);

    if ($scheme === 'public') {
      $target_folder = !empty($config['public_folder']) ? $config['public_folder'] . '/' : 's3fs-public/';
      $key_path = $target_folder . $key_path;
      $src_key_path = $target_folder . $src_key_path;
    }
    elseif ($scheme === 'private') {
      $target_folder = !empty($config['private_folder']) ? $config['private_folder'] . '/' : 's3fs-private/';
      $key_path = $target_folder . $key_path;
      $src_key_path = $target_folder . $src_key_path;
    }

    if (!empty($config['root_folder'])) {
      $key_path = $config['root_folder'] . '/' . $key_path;
      $src_key_path = $config['root_folder'] . '/' . $src_key_path;
    }

    $contentType = $this->mimeGuesser->guessMimeType($key_path);

    try {
      $s3 = $this->s3fs->getAmazonS3Client($config);
    }
    catch (S3fsException $e) {
      $exception_variables = Error::decodeException($e);
      $this->s3fsLogger->error('AmazonS3Client error: @message', $exception_variables);
      return FALSE;
    }

    $copyParams = [
      'Bucket' => $config['bucket'],
      'Key' => $key_path,
      'CopySource' => $s3::encodeKey($config['bucket'] . '/' . $src_key_path),
      'ContentType' => $contentType,
      'MetadataDirective' => 'REPLACE',
    ];

    if (!empty($config['encryption'])) {
      $copyParams['ServerSideEncryption'] = $config['encryption'];
    }

    // Set the Cache-Control header, if the user specified one.
    if (!empty($config['cache_control_header'])) {
      $copyParams['CacheControl'] = $config['cache_control_header'];
    }

    $uploadAsPrivate = Settings::get('s3fs.upload_as_private');

    if ($scheme !== 'private' && !$uploadAsPrivate) {
      $copyParams['ACL'] = 'public-read';
    }

    $keyPaths = [
      'from_key' => $src_key_path,
      'to_key' => $key_path,
    ];

    $this->moduleHandler->alter('s3fs_copy_params', $copyParams, $keyPaths);

    try {
      $s3->copyObject($copyParams);
    }
    catch (AwsException $e) {
      $exception_variables = Error::decodeException($e);
      // In some cases the getAwsErrorMessage() method is returns an empty
      // string. Like when we try to use a nonexistent bucket.
      if (!empty($e->getAwsErrorMessage())) {
        $exception_variables['message'] = $e->getAwsErrorMessage();
      }
      $exception_variables['@request_id'] = $e->getAwsRequestId();
      $this->s3fsLogger->error('An error occurred when uploading a file: @message. Request ID: @request_id', $exception_variables);
      return FALSE;
    }
    catch (\Exception $e) {
      $exception_variables = Error::decodeException($e);
      $this->s3fsLogger->error('An error occurred when uploading a file: @message.', $exception_variables);
      return FALSE;
    }

    $wrapper->writeUriToCache($destination);
    return TRUE;
  }

  /**
   * Ensures that an ENUM is used for $fileExists.
   *
   * @param \Drupal\Core\File\FileExists|int $fileExists
   *   Submitted $fileExists value.
   *
   * @return \Drupal\Core\File\FileExists
   *   Submission converted to ENUM.
   */
  private function convertFileExists(FileExists|int $fileExists): FileExists {
    if (!$fileExists instanceof FileExists) {
      // @phpstan-ignore-next-line
      $fileExists = FileExists::fromLegacyInt($fileExists, __METHOD__);
    }
    return $fileExists;
  }

}
