<?php

namespace Drupal\s3fs\StreamWrapper;

use Aws\Middleware;
use Aws\S3\Exception\S3Exception;
use Aws\S3\StreamWrapper;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\s3fs\S3fsException;
use Drupal\s3fs\S3fsServiceInterface;
use Drupal\s3fs\Traits\S3fsMetadataHelperTrait;
use Drupal\s3fs\Traits\S3fsPathsTrait;
use Psr\Http\Message\RequestInterface;

/**
 * Defines a Drupal s3 (s3://) stream wrapper class.
 *
 * Provides support for storing files on the amazon s3 file system with the
 * Drupal file interface.
 */
class S3fsStream extends StreamWrapper implements StreamWrapperInterface {

  use StringTranslationTrait;
  use S3fsMetadataHelperTrait;
  use S3fsPathsTrait;

  const API_VERSION = '2006-03-01';

  /**
   * Hash of opened stream parameters.
   *
   * @var array
   */
  private $params = [];

  /**
   * Module configuration for stream.
   *
   * @var array
   */
  protected $config = [];

  /**
   * Mode in which the stream was opened.
   *
   * @var string
   */
  private $mode;

  /**
   * Instance uri referenced as "<scheme>://key".
   *
   * @var string
   */
  protected $uri = NULL;

  /**
   * The AWS SDK for PHP S3Client object.
   *
   * @var \Aws\S3\S3Client
   */
  protected $s3 = NULL;

  /**
   * The S3fs Service.
   *
   * @var \Drupal\s3fs\S3fsServiceInterface
   */
  protected $s3fs = NULL;

  /**
   * The opened protocol (e.g., "s3").
   *
   * @var string
   */
  private $protocol = 's3';

  /**
   * Custom domain used to access files.
   *
   * @var array
   */
  protected $cname = NULL;

  /**
   * Directory listing used by the dir_* methods.
   *
   * @var array
   */
  protected $dir = NULL;

  /**
   * Map for files that should be delivered with a torrent URL.
   *
   * @var array
   */
  protected $torrents = [];

  /**
   * Files that the user has said must be downloaded, rather than viewed.
   *
   * @var array
   */
  protected $saveas = [];

  /**
   * Files which should be created with URLs that eventually time out.
   *
   * @var array
   */
  protected $presignedURLs = [];

  /**
   * All files included public files will be private if this feature is enable.
   *
   * @var bool
   */
  protected $uploadAsPrivate = FALSE;

  /**
   * Default map for determining file mime types.
   *
   * @var array
   */
  protected static $mimeTypeMapping = NULL;

  /**
   * Indicates the current error state in the wrapper.
   *
   * @var bool
   */
  protected $errorState = FALSE;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * S3fsStream constructor.
   *
   * Creates the \Aws\S3\S3Client client object and activates the options
   * specified on the S3 File System Settings page.
   *
   * @throws \Drupal\s3fs\S3fsException
   *   Any exception raised.
   */
  public function __construct() {
    $this->streamWrapperManager = \Drupal::service('stream_wrapper_manager');

    // Since S3fsStreamWrapper is always constructed with the same inputs (the
    // file URI is not part of construction), we store the constructed settings
    // statically. This is important for performance because the way Drupal's
    // APIs are used causes stream wrappers to be frequently re-constructed.
    // Get the S3 Client object and register the stream wrapper again so it is
    // configured as needed.
    $settings = &drupal_static('S3fsStream_constructed_settings');

    if ($settings !== NULL) {
      $this->config = $settings['config'];
      $this->cname = $settings['cname'];
      $this->torrents = $settings['torrents'];
      $this->presignedURLs = $settings['presignedURLs'];
      $this->saveas = $settings['saveas'];
      $this->s3fs = $settings['s3fs'];
      $this->s3 = $settings['s3'];
      $this->uploadAsPrivate = $settings['upload_as_private'];
      $this->register($this->s3);
      return;
    }

    $this->s3fs = \Drupal::service('s3fs');

    $config = \Drupal::config('s3fs.settings');
    foreach ($config->get() as $prop => $value) {
      $this->config[$prop] = $value;
    }

    $this->s3 = $this->getClient();

    $this->register($this->s3);
    $this->context = stream_context_get_default();
    stream_context_set_option($this->context, 's3', 'seekable', TRUE);

    // Always use HTTPS when the page is being served via HTTPS, to avoid
    // complaints from the browser about insecure content.
    $request = \Drupal::request();
    $is_https = !empty($request) && $request->isSecure();
    if ($is_https) {
      // We change the config itself, rather than simply using $is_https in
      // the following if condition, because $this->config['use_https'] gets
      // used again later.
      $this->config['use_https'] = TRUE;
    }

    // CNAME support for customizing S3 URLs.
    // If use_cname is not enabled, file URLs do not use $this->cname.
    if (!empty($this->config['use_cname']) && !empty($this->config['domain'])) {
      $domain = $this->config['domain'];
      if ($domain) {
        $domainpart = explode(':', $domain, 2);
        if (!empty($domainpart[1])) {
          $this->cname['port'] = (int) $domainpart[1];
        }
        $this->cname['host'] = $domainpart[0];
        $this->cname['scheme'] = !empty($this->config['use_https']) ? 'https' : 'http';
      }
      else {
        // Throw an error if the user overrode use_cname without setting a
        // domain in settings.php.
        throw new S3fsException($this->t('The "Use CNAME" option is enabled, but no Domain Name has been set.'));
      }
    }

    // Convert the torrents string to an array.
    if (!empty($this->config['torrents'])) {
      foreach (explode("\n", $this->config['torrents']) as $line) {
        $blob = trim($line);
        if ($blob) {
          $this->torrents[] = $blob;
        }
      }
    }

    // Convert the presigned URLs string to an associative array like
    // [blob => timeout].
    if (!empty($this->config['presigned_urls'])) {
      foreach (explode(PHP_EOL, $this->config['presigned_urls']) as $line) {
        $blob = trim($line);
        if ($blob) {
          if (preg_match('/(.*)\|(.*)/', $blob, $matches)) {
            $blob = $matches[2];
            $timeout = $matches[1];
            $this->presignedURLs[$blob] = $timeout;
          }
          else {
            $this->presignedURLs[$blob] = 60;
          }
        }
      }
    }

    // Convert the forced save-as string to an array.
    if (!empty($this->config['saveas'])) {
      foreach (explode(PHP_EOL, $this->config['saveas']) as $line) {
        $blob = trim($line);
        if ($blob) {
          $this->saveas[] = $blob;
        }
      }
    }

    $this->uploadAsPrivate = Settings::get('s3fs.upload_as_private');

    // Save all the work we just did, so that subsequent S3fsStreamWrapper
    // constructions don't have to repeat it.
    $settings['config'] = $this->config;
    $settings['cname'] = $this->cname;
    $settings['torrents'] = $this->torrents;
    $settings['presignedURLs'] = $this->presignedURLs;
    $settings['saveas'] = $this->saveas;
    $settings['s3fs'] = $this->s3fs;
    $settings['s3'] = $this->s3;
    $settings['upload_as_private'] = $this->uploadAsPrivate;
  }

  /**
   * {@inheritdoc}
   */
  private function getClient() {
    return $this->s3fs->getAmazonS3Client($this->config);
  }

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->t('S3 File System');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Amazon Simple Storage Service.');
  }

  /**
   * Gets the path that the wrapper is responsible for.
   *
   * This function isn't part of DrupalStreamWrapperInterface, but the rest
   * of Drupal calls it as if it were, so we need to define it.
   *
   * @return string
   *   The empty string. Since this is a remote stream wrapper,
   *   it has no directory path.
   *
   * @see \Drupal\Core\File\LocalStream::getDirectoryPath()
   */
  public function getDirectoryPath() {
    return '';
  }

  /**
   * {@inheritdoc}
   *
   * Sets the stream resource URI. URIs are formatted as "<scheme>://filepath".
   *
   * @param string $uri
   *   The URI that should be used for this instance.
   */
  public function setUri($uri) {
    $uri = $this->resolvePath($uri);
    // Perform an access check before allowing URI to be set.
    $this->uri = $uri;
  }

  /**
   * {@inheritdoc}
   *
   * Returns the stream resource URI, which looks like "<scheme>://filepath".
   *
   * @return string
   *   The current URI of the instance.
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * {@inheritdoc}
   *
   * This wrapper does not support realpath().
   *
   * @return bool
   *   Always returns FALSE.
   */
  public function realpath() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Returns a web accessible URL for the resource.
   *
   * The format of the returned URL will be different depending on how the S3
   * integration has been configured on the S3 File System admin page.
   *
   * @return string
   *   A web accessible URL for the resource.
   */
  public function getExternalUrl() {
    // In case we're on Windows, replace backslashes with forward-slashes.
    // Note that $uri is the unaltered value of the File's URI, while
    // $s3_key may be changed at various points to account for implementation
    // details on the S3 side (e.g. root_folder, s3fs-public).
    $s3_key = str_replace('\\', '/', $this->streamWrapperManager::getTarget($this->uri));

    // Is this an ImageStyle Path.
    $isImageStylePath = FALSE;

    // When generating an image derivative URL, e.g. styles/thumbnail/blah.jpg,
    // if the file doesn't exist, provide a URL to s3fs's special version of
    // image_style_deliver(), which will create the derivative when that URL
    // gets requested.  When the file does exist we need to calculate an itok
    // in case the link requires presigning.
    $path_parts = explode('/', $s3_key);
    if ($path_parts[0] == 'styles' && substr($s3_key, -4) != '.css') {
      $isImageStylePath = TRUE;

      // Style derivative does not yet exist in the bucket.
      if (!$this->getS3fsObject($this->uri)) {
        // The style delivery path looks like: s3/files/styles/thumbnail/...
        // And $path_parts looks like ['styles', 'thumbnail', ...],
        // so just prepend s3/files/.
        array_unshift($path_parts, 's3', 'files');
        $path = implode('/', $path_parts);
        return $GLOBALS['base_url'] . '/' . UrlHelper::encodePath($path);
      }

      // Generate itok key in case link that need to be presigned.
      $suppressItok = \Drupal::config('image.settings')->get('suppress_itok_output');
      if (!$suppressItok) {
        $imageStyleName = $path_parts[1];
        $srcScheme = $path_parts[2];
        // Strip off 'style', ImageStyleName and scheme.
        array_splice($path_parts, 0, 3);
        $srcImageUri = $srcScheme . '://' . implode('/', $path_parts);

        $itok = \Drupal::entityTypeManager()
          ->getStorage('image_style')
          ->load($imageStyleName)
          ->getPathToken($srcImageUri);

      }

    }

    // Deal with public:// files.
    if (StreamWrapperManager::getScheme($this->uri) == 'public') {
      // public:// files are stored in S3 inside the s3fs_public_folder.
      $public_folder = !empty($this->config['public_folder']) ? $this->config['public_folder'] : 's3fs-public';
      // If use_cname check if we are striping the path.
      if (!empty($this->config['use_cname'])) {
        if (!empty($this->config['domain_root']) && $this->config['domain_root'] !== 'public') {
          $s3_key = "{$public_folder}/$s3_key";
        }
      }
      // When not using cname we always prepend the public_folder path.
      else {
        $s3_key = "{$public_folder}/$s3_key";
      }
    }

    // Set up the URL settings as specified in our settings page.
    $url_settings = [
      'torrent' => FALSE,
      'presigned_url' => FALSE,
      'timeout' => 60,
      'forced_saveas' => FALSE,
      'api_args' => [],
      'custom_GET_args' => [],
    ];

    // Presigned URLs.
    foreach ($this->presignedURLs as $blob => $timeout) {
      // ^ is used as the delimiter because it's an illegal character in URLs.
      if (preg_match("^$blob^", $s3_key)) {
        $url_settings['presigned_url'] = TRUE;
        $url_settings['timeout'] = $timeout;
        break;
      }
    }
    // Forced Save As.
    foreach ($this->saveas as $blob) {
      if (preg_match("^$blob^", $s3_key)) {
        $filename = basename($s3_key);
        $url_settings['api_args']['ResponseContentDisposition'] = "attachment; filename=\"$filename\"";
        $url_settings['forced_saveas'] = TRUE;
        break;
      }
    }

    // Allow other modules to change the URL settings.
    \Drupal::moduleHandler()->alter('s3fs_url_settings', $url_settings, $s3_key);

    // Prepend root_folder if necessary.
    if (!empty($this->config['root_folder'])) {
      // When using cname only append if domain_root is none.
      if (!empty($this->config['use_cname'])) {
        if (
            // domain_root config has been provided.
            !empty($this->config['domain_root'])
            // domain_root is set to not strip any leading paths.
            && ($this->config['domain_root'] === 'none')) {
          $s3_key = "{$this->config['root_folder']}/$s3_key";
        }
      }
      // Not using cname, always append.
      else {
        $s3_key = "{$this->config['root_folder']}/$s3_key";
      }
    }

    $commandSettings = [
      'Bucket' => $this->config['bucket'],
      'Key'    => $s3_key,
    ];

    // Handle presign expire timeout.
    $expires = NULL;
    if ($url_settings['presigned_url']) {
      $expires = "+{$url_settings['timeout']} seconds";

    }
    else {
      // Due to Amazon's security policies (see Request client parameters @
      // http://docs.aws.amazon.com/AmazonS3/latest/API/RESTObjectGET.html),
      // only signed requests can use request parameters.
      // Thus, we must provide an expiry time for any URLs which specify
      // Response* API args.
      foreach ($url_settings['api_args'] as $key => $arg) {
        if (strpos($key, 'Response') === 0) {
          // @see https://aws.amazon.com/premiumsupport/knowledge-center/presigned-url-s3-bucket-expiration/
          // Max limit: Instance Credential 6h, STS 3d, IAM 7d.
          $expires = "21600 seconds";
          break;
        }
      }
    }

    // If this file is versioned, attach the version number to
    // ensure that browser caches will be bypassed upon version changes.
    $meta = $this->readCache($this->uri);
    if (!empty($meta['version'])) {
      $commandSettings['VersionId'] = $meta['version'];
    }

    foreach ($url_settings['api_args'] as $key => $arg) {
      $commandSettings[$key] = $arg;
    }

    try {
      $command = $this->s3->getCommand('GetObject', $commandSettings);
    }
    catch (\Exception $e) {
      $this->logException($e);
      return Url::fromUserInput('/')->toString();
    }
    // Make sure the url scheme is set correctly.
    $scheme = !empty($this->config['use_https']) ? 'https' : 'http';
    $command->getHandlerList()->appendBuild(
      Middleware::mapRequest(function (RequestInterface $request) use (
        $scheme
      ) {

        $uri = $request->getUri();

        if ($uri->getPort() == 80 || $uri->getPort() == 443) {
          // Reset port value for use with scheme select.
          $uri = $uri->withPort(NULL);
        }

        $uri = $uri->withScheme($scheme);

        return $request->withUri($uri);
      }),
      'set-scheme'
    );

    if (!empty($url_settings['custom_GET_args'])) {
      // If another module added a 'custom_GET_args' array to the url settings,
      // add a build handler to process them.
      $command->getHandlerList()->appendBuild(
          Middleware::mapRequest(function (RequestInterface $request) use (
            $url_settings
          ) {
            $uri = $request
              ->getUri()
              ->withQueryValues($request->getUri(), $url_settings['custom_GET_args']);

            return $request->withUri($uri, TRUE);
          }),
          'add-getargs'
      );
    }

    if (!empty($this->config['use_cname'])) {
      $cname = $this->cname;
      $command->getHandlerList()->appendBuild(
          Middleware::mapRequest(function (RequestInterface $request) use (
            $cname
          ) {

            $uri = $request->getUri()
              ->withHost($cname['host']);

            if (!empty($cname['port'])) {
              $uri = $uri->withPort($cname['port']);
            }

            return $request->withUri($uri);
          }),
          'use-cname'
      );
    }

    if ($isImageStylePath && !$suppressItok) {
      $command->getHandlerList()->appendBuild(
        Middleware::mapRequest(function (RequestInterface $request) use (
          $itok
        ) {
          $uri = $request->getUri();
          $uri = $uri->withQueryValue($uri, 'itok', $itok);
          return $request->withUri($uri);
        }),
        'add-itok'
      );
    }

    if (!empty($expires)) {
      // Need to use a presign URL.
      try {
        $external_url = (string) $this->s3->createPresignedRequest($command, $expires)->getUri();
      }
      catch (\Exception $e) {
        $this->logException($e);
        return Url::fromUserInput('/')->toString();
      }

      if ($isImageStylePath && !$suppressItok) {
        $parsedUrl = UrlHelper::parse($external_url);
        $queryParams = UrlHelper::filterQueryParameters($parsedUrl['query'], ['itok']);
        $external_url = $parsedUrl['path'] . '?' . UrlHelper::buildQuery($queryParams);
      }

    }
    else {
      // No special request given, we can generate the link.
      if (empty($this->config['use_cname'])) {
        try {
          $external_url = $this->s3->getObjectUrl($this->config['bucket'], $s3_key);
        }
        catch (\Exception $e) {
          $this->logException($e);
          return Url::fromUserInput('/')->toString();
        }
        if (empty($this->config['use_https'])) {
          // Forced HTTPS not enabled and not an HTTPS page load.
          $external_url = preg_replace('#^https:#', 'http:', $external_url);
        }
      }
      else {
        $external_url = $this->cname['scheme'] . '://' . $this->cname['host'];
        if (!empty($this->cname['port'])) {
          $external_url = $external_url . ':' . $this->cname['port'];
        }
        if (!empty($this->config['use_path_style_endpoint'])) {
          $external_url = $external_url . '/' . $this->config['bucket'];
        }
        $external_url = $external_url . '/' . UrlHelper::encodePath($s3_key);
      }

      if (!empty($meta['version'])) {
        $external_url = $this->appendGetArg($external_url, 'VersionId', $meta['version']);
      }

      foreach ($url_settings['custom_GET_args'] as $name => $value) {
        $external_url = $this->appendGetArg($external_url, $name, $value);
      }

      // Torrents can only be created for publicly-accessible files:
      // https://forums.aws.amazon.com/thread.jspa?threadID=140949
      foreach ($this->torrents as $blob) {
        if (preg_match("^$blob^", $s3_key)) {
          // You get a torrent URL by adding a "torrent" GET arg.
          $external_url = $this->appendGetArg($external_url, 'torrent');
          break;
        }
      }

    }

    return $external_url;
  }

  /**
   * {@inheritdoc}
   *
   * Support for fopen(), file_get_contents(), file_put_contents() etc.
   *
   * @param string $uri
   *   The URI of the file to open.
   * @param string $mode
   *   The file mode. Only 'r', 'w', 'a', and 'x' are supported.
   * @param int $options
   *   A bit mask of STREAM_USE_PATH and STREAM_REPORT_ERRORS.
   * @param string $opened_path
   *   An OUT parameter populated with the path which was opened.
   *   This wrapper does not support this parameter.
   *
   * @return bool
   *   TRUE if file was opened successfully. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-open.php
   */
// phpcs:disable
  public function stream_open($uri, $mode, $options, &$opened_path) {
// phpcs:enable
    $uri = $this->resolvePath($uri);
    if (mb_strlen($uri) > S3fsServiceInterface::MAX_URI_LENGTH) {
      return FALSE;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $uri)) {
      return FALSE;
    }

    $this->setUri($uri);
    $converted = $this->convertUriToKeyedPath($uri);

    $s3options = $this->getOptions();

    // Allow other modules to alter the stream open params.
    \Drupal::moduleHandler()->alter('s3fs_stream_open_params', $s3options[$this->protocol], $converted);

    if ($this->context == NULL) {
      // This should never occur.
      return FALSE;
    }
    $this->setStreamContextOptions($this->context, $s3options);

    return parent::stream_open($converted, $mode, $options, $opened_path);
  }

  /**
   * {@inheritdoc}
   *
   * This wrapper does not support flock().
   *
   * @return bool
   *   Always Returns FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-lock.php
   */
// phpcs:disable
  public function stream_lock($operation) {
// phpcs:enable
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for fflush(). Flush current cached stream data to a file in S3.
   *
   * @return bool
   *   TRUE if data was successfully stored in S3.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-flush.php
   */
// phpcs:disable
  public function stream_flush() {
// phpcs:enable
    // Prepare upload parameters.
    $options = $this->getOptions();
    $params = $this->getCommandParams($this->getUri());

    $mimeGuesser = \Drupal::service('s3fs.mime_type.guesser');
    if (method_exists($mimeGuesser, 'guessMimeType')) {
      $contentType = $mimeGuesser->guessMimeType($params['Key']);
    }
    else {
      $contentType = $mimeGuesser->guess($params['Key']);
    }

    $options[$this->protocol]['ContentType'] = $contentType;

    if (
          !$this->uploadAsPrivate
      &&  StreamWrapperManager::getScheme($this->uri) !== 'private'
    ) {
      // All non-private files uploaded to S3 must be set to public-read, or
      // users' browsers will get PermissionDenied errors, and torrent URLs
      // won't work.
      $options[$this->protocol]['ACL'] = 'public-read';
    }
    // Set the Cache-Control header, if the user specified one.
    if (!empty($this->config['cache_control_header'])) {
      $options[$this->protocol]['CacheControl'] = $this->config['cache_control_header'];
    }

    if (!empty($this->config['encryption'])) {
      $options[$this->protocol]['ServerSideEncryption'] = $this->config['encryption'];
    }

    // Allow other modules to alter the upload params.
    \Drupal::moduleHandler()->alter('s3fs_upload_params', $options[$this->protocol]);

    if ($this->context == NULL) {
      // This should never occur.
      return FALSE;
    }
    $this->setStreamContextOptions($this->context, $options);

    if (parent::stream_flush()) {
      $this->writeUriToCache($this->uri);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * This wrapper does not support touch(), chmod(), chown(), or chgrp().
   *
   * Manual recommends return FALSE for not implemented options, but Drupal
   * require TRUE in some cases like chmod for avoid watchdog errors.
   *
   * Returns FALSE if the option is not included in bypassed_options array
   * otherwise, TRUE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-metadata.php
   * @see \Drupal\Core\File\FileSystem::chmod()
   */
// phpcs:disable
  public function stream_metadata($uri, $option, $value) {
// phpcs:enable
    $bypassed_options = [STREAM_META_ACCESS];
    return in_array($option, $bypassed_options);
  }

  /**
   * {@inheritdoc}
   *
   * Since Windows systems do not allow it and it is not needed for most use
   * cases anyway, this method is not supported on local files and will trigger
   * an error and return false. If needed, custom subclasses can provide
   * OS-specific implementations for advanced use cases.
   */
// phpcs:disable
  public function stream_set_option($option, $arg1, $arg2) {
// phpcs:enable
    trigger_error('stream_set_option() not supported for local file based stream wrappers', E_USER_WARNING);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * This wrapper does not support stream_truncate.
   *
   * Always returns FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-truncate.php
   */
// phpcs:disable
  public function stream_truncate($new_size) {
// phpcs:disable
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for unlink().
   *
   * @param string $uri
   *   The uri of the resource to delete.
   *
   * @return bool
   *   TRUE if resource was successfully deleted, regardless of whether or not
   *   the file actually existed.
   *   FALSE if the call to S3 failed, in which case the file will not be
   *   removed from the cache.
   *
   * @see http://php.net/manual/en/streamwrapper.unlink.php
   */
  public function unlink($uri) {
    $uri = $this->resolvePath($uri);
    $this->setUri($uri);
    $converted = $this->convertUriToKeyedPath($uri);
    if (parent::unlink($converted)) {
      $this->deleteCache($uri);
      clearstatcache(TRUE, $uri);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for rename().
   *
   * If $to_uri exists, this file will be overwritten. This behavior is
   * identical to the PHP rename() function.
   *
   * @param string $from_uri
   *   The uri of the file to be renamed.
   * @param string $to_uri
   *   The new uri for the file.
   *
   * @return bool
   *   TRUE if file was successfully renamed. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.rename.php
   */
  public function rename($from_uri, $to_uri) {
    $from_uri = $this->resolvePath($from_uri);
    $to_uri = $this->resolvePath($to_uri);
    if ($this->isDir($from_uri)) {
      // AWS SDK doesn't support moving 'directories'.
      return FALSE;
    }

    if (mb_strlen($to_uri) > S3fsServiceInterface::MAX_URI_LENGTH) {
      return FALSE;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $to_uri)) {
      return FALSE;
    }

    // Set access for new item in stream context.
    if (!$this->uploadAsPrivate
      && StreamWrapperManager::getScheme($to_uri) !== 'private'
    ) {
      stream_context_set_option($this->context, 's3', 'ACL', 'public-read');
    }

    $from_key = $this->convertUriToKeyedPath($from_uri);
    $to_key = $this->convertUriToKeyedPath($to_uri);

    $rename_context = [
      'from_key' => $from_key,
      'to_key' => $to_key,
    ];

    $options = $this->getOptions();

    // Allow other modules to alter the rename params.
    \Drupal::moduleHandler()->alter('s3fs_copy_params', $options[$this->protocol], $rename_context);

    if ($this->context == NULL) {
      // This should never occur.
      return FALSE;
    }
    $this->setStreamContextOptions($this->context, $options);

    // If parent succeeds in renaming, updated local metadata and cache.
    if (parent::rename($from_key, $to_key)) {
      $metadata = $this->readCache($from_uri);
      $metadata['uri'] = $to_uri;
      $this->writeCache($metadata);
      $this->deleteCache($from_uri);
      clearstatcache(TRUE, $from_uri);
      clearstatcache(TRUE, $to_uri);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Gets the name of the parent directory of a given path.
   *
   * This method is usually accessed through:
   * \Drupal::service('file_system')->dirname(),
   * which wraps around the normal PHP dirname() function, since it doesn't
   * support stream wrappers.
   *
   * @param string $uri
   *   An optional URI.
   *
   * @return string
   *   The directory name, or FALSE if not applicable.
   *
   * @see \Drupal::service('file_system')->dirname()
   */
  public function dirname($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    } else {
      $uri = $this->resolvePath($uri);
    }
    $scheme = StreamWrapperManager::getScheme($uri);
    $dirname = dirname($this->streamWrapperManager::getTarget($uri));

    // When the dirname() call above is given '$scheme://', it returns '.'.
    // But '$scheme://.' is an invalid uri, so we return "$scheme://" instead.
    if ($dirname == '.') {
      $dirname = '';
    }

    return "$scheme://$dirname";
  }

  /**
   * {@inheritdoc}
   *
   * Support for mkdir().
   *
   * @param string $uri
   *   The URI to the directory to create.
   * @param int $mode
   *   Permission flags - see mkdir().
   * @param int $options
   *   A bit mask of STREAM_REPORT_ERRORS and STREAM_MKDIR_RECURSIVE.
   *
   * @return bool
   *   TRUE if the directory was successfully created. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.mkdir.php
   */
  public function mkdir($uri, $mode, $options) {
    // Resolve relative path and strip any trailing slash that we mustn't
    // store in the cache.
    $uri = $this->resolvePath($uri);

    if (StreamWrapperManager::getTarget($uri) == '') {
      // Don't store the root path in the database.
      // Always consider this successful.
      return TRUE;
    }

    if (mb_strlen($uri) > S3fsServiceInterface::MAX_URI_LENGTH) {
      return FALSE;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $uri)) {
      return FALSE;
    }

    clearstatcache(TRUE, $uri);
    // If this URI already exists in the cache, return TRUE if it's a folder
    // (so that recursive calls won't improperly report failure when they
    // reach an existing ancestor), or FALSE if it's a file (failure).
    $test_metadata = $this->readCache($uri);
    if ($test_metadata) {
      return (bool) $test_metadata['dir'];
    }

    $metadata = $this->s3fs->convertMetadata($uri, []);
    $this->writeCache($metadata);

    // If the STREAM_MKDIR_RECURSIVE option was specified, also create all the
    // ancestor folders of this uri, except for the root directory.
    $parent_dir = \Drupal::service('file_system')->dirname($uri);
    if (($options & STREAM_MKDIR_RECURSIVE) && $this->streamWrapperManager::getTarget($parent_dir) != '') {
      return $this->mkdir($parent_dir, $mode, $options);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for rmdir().
   *
   * @param string $uri
   *   The URI to the folder to delete.
   * @param int $options
   *   A bit mask of STREAM_REPORT_ERRORS.
   *
   * @return bool
   *   TRUE if folder is successfully removed.
   *   FALSE if $uri isn't a folder, or the folder is not empty.
   *
   * @see http://php.net/manual/en/streamwrapper.rmdir.php
   */
  public function rmdir($uri, $options) {
    $uri = $this->resolvePath($uri);
    if (!$this->isDir($uri)) {
      return FALSE;
    }

    // We need a version of $uri with no / because folders are cached with no /,
    // this is provided by resolvePath() above.
    // We also need one with the /, because it might be a file in S3 that
    // ends with /. In addition, we must differentiate against files with this
    // folder's name as a substring.
    // e.g. rmdir('s3://foo/bar') should ignore s3://foo/barbell.jpg.;
    $slash_path = $uri . '/';

    // Check if the folder is empty.
    $query = \Drupal::database()->select('s3fs_file', 's');
    $query->fields('s')
      ->condition('uri', $query->escapeLike($slash_path) . '%', 'LIKE');

    $file_count = $query->countQuery()->execute()->fetchField();

    // If the folder is empty at time of query it is eligible for deletion.
    if ($file_count == 0) {
      // Suppress race triggerError('Subfolder is not empty').
      if (@parent::rmdir($this->convertUriToKeyedPath($uri), $options)) {
        $this->deleteCache($uri);
        clearstatcache(TRUE, $uri);
        return TRUE;
      }
    }

    // The folder is non-empty.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for stat().
   *
   * @param string $uri
   *   The URI to get information about.
   * @param int $flags
   *   A bit mask of STREAM_URL_STAT_LINK and STREAM_URL_STAT_QUIET.
   *   S3fsStreamWrapper ignores this value.
   *
   * @return array
   *   An array with file status, or FALSE in case of an error.
   *
   * @see http://php.net/manual/en/streamwrapper.url-stat.php
   */
// phpcs:disable
  public function url_stat($uri, $flags) {
// phpcs:enable
    $uri = $this->resolvePath($uri);
    $this->setUri($uri);
    return $this->stat($uri);
  }

  /**
   * {@inheritdoc}
   *
   * Support for opendir().
   *
   * @param string $uri
   *   The URI to the directory to open.
   * @param int $options
   *   A flag used to enable safe_mode.
   *   This wrapper doesn't support safe_mode, so this parameter is ignored.
   *
   * @return bool
   *   TRUE on success. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-opendir.php
   */
// phpcs:disable
  public function dir_opendir($uri, $options = NULL) {
// phpcs:enable
    $uri = $this->resolvePath($uri);
    if (!$this->isDir($uri)) {
      return FALSE;
    }
    $this->uri = $uri;

    $public_folder = !empty($this->config['public_folder']) ? $this->config['public_folder'] : 's3fs-public';
    $public_folder = trim($public_folder, '/');
    $private_folder = !empty($this->config['private_folder']) ? $this->config['private_folder'] : 's3fs-private';
    $private_folder = trim($private_folder, '/');

    $scheme = StreamWrapperManager::getScheme($uri);
    $slash_path = $uri . '/';

    // If this path was originally a root folder (e.g. s3://), the above code
    // added an extra slash so we need to remove it now.
    if ($slash_path == "$scheme:///") {
      $slash_path = "$scheme://";
    }

    // Get the list of paths for files and folders which are children of the
    // specified folder, but not grandchildren.
    $query = \Drupal::database()->select('s3fs_file', 's');
    $query->fields('s', ['uri']);
    $query->condition('uri', $query->escapeLike($slash_path) . '%', 'LIKE');
    $query->condition('uri', $query->escapeLike($slash_path) . '%/%', 'NOT LIKE');

    // Prevent cross scheme directory structure leakage.
    // This shouldn't be an issue for most scenarios, however if the
    // private/public folder ever make it into the cache under another scheme
    // this ensures a directory scan will not reveal them.
    switch ($scheme) {
      case 's3':
        // Prevent private and public folder from showing nested under s3://.
        $query->condition('uri', 's3://' . $query->escapeLike($public_folder) . '/%', 'NOT LIKE');
        $query->condition('uri', 's3://' . $public_folder, '<>');
        $query->condition('uri', 's3://' . $query->escapeLike($private_folder) . '/%', 'NOT LIKE');
        $query->condition('uri', 's3://' . $private_folder, '<>');
        break;

      case 'public':
        // Prevent the private folder from appearing nested under public://.
        if (mb_strpos($private_folder, $public_folder) === 0) {
          $private_search = '#^' . $private_folder . '#';
          $prefix = preg_replace($private_search, '', $public_folder);
          $query->condition('uri', 'public://' . $query->escapeLike($prefix) . '/%', 'NOT LIKE');
          $query->condition('uri', 'public://' . $prefix, '<>');
        }
        break;

      case 'private':
        // Prevent the public folder from showing up nested under
        // private://.
        if (mb_strpos($public_folder, $private_folder) === 0) {
          $public_search = '#^' . $public_folder . '#';
          $prefix = preg_replace($public_search, '', $private_folder);
          $query->condition('uri', 'private://' . $query->escapeLike($prefix) . '/%', 'NOT LIKE');
          $query->condition('uri', 'private://' . $prefix, '<>');
        }
        break;
    }

    $child_paths = $query->execute()->fetchCol(0);

    $this->dir = [];
    foreach ($child_paths as $child_path) {
      $this->dir[] = basename($child_path);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for readdir().
   *
   * @return string
   *   The next filename, or FALSE if there are no more files in the directory.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-readdir.php
   */
// phpcs:disable
  public function dir_readdir() {
// phpcs:enable
    $current = current($this->dir);
    if ($current) {
      next($this->dir);
    }
    return $current;
  }

  /**
   * Rewind directory handle.
   */
  // phpcs:disable
  public function dir_rewinddir(): bool {
  // phpcs:enable
    return $this->dir_opendir($this->uri);
  }

  /**
   * Public Functions for External Use of the Wrapper.
   */

  /**
   * Wait for the specified file to exist in the bucket.
   *
   * @param string $uri
   *   The URI of the file.
   *
   * @return bool
   *   Returns TRUE once the waiting finishes, or FALSE if the file does not
   *   begin to exist within 10 seconds.
   */
  public function waitUntilFileExists($uri) {
    $this->preventCrossSchemeAccess($uri);
    // Retry ten times, once every second.
    $params = $this->getCommandParams($uri);
    $params['@waiter'] = [
      'delay' => 1,
      'maxAttempts' => 10,
    ];
    try {
      $this->s3->waitUntil('ObjectExists', $params);
      return TRUE;
    }
    catch (S3fsException $e) {
      $this->logException($e);
      return FALSE;
    }
    catch (\Exception $e) {
      $this->logException($e);
      return FALSE;
    }
  }

  /**
   * Write the file at the given URI into the metadata cache.
   *
   * This function is public so that other code can upload files to S3 and
   * then have us write the correct metadata into our cache.
   */
  public function writeUriToCache($uri) {
    $this->preventCrossSchemeAccess($uri);
    if ($this->waitUntilFileExists($uri)) {
      $metadata = $this->getS3Metadata($uri);
      if (!empty($metadata)) {
        $this->writeCache($metadata);
        clearstatcache(TRUE, $uri);
      }
    }
  }

  /**
   * Internal Functions.
   */

  /**
   * Get the status of the file with the specified URI.
   *
   * Implementation of a stat method to ensure that remote files don't fail
   * checks when they should pass.
   *
   * @param string $uri
   *   The uri of the resource.
   *
   * @return array|bool
   *   An array with file status, or FALSE if the file doesn't exist.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-stat.php
   */
  protected function stat($uri) {
    $uri = $this->resolvePath($uri);
    $metadata = $this->getS3fsObject($uri);
    if ($metadata) {
      $stat = [];
      $stat[0] = $stat['dev'] = 0;
      $stat[1] = $stat['ino'] = 0;
      // Use the S_IFDIR posix flag for directories, S_IFREG for files.
      // All files are considered writable, so OR in 0777.
      $stat[2] = $stat['mode'] = ($metadata['dir'] ? 0040000 : 0100000) | 0777;
      $stat[3] = $stat['nlink'] = 0;
      $stat[4] = $stat['uid'] = 0;
      $stat[5] = $stat['gid'] = 0;
      $stat[6] = $stat['rdev'] = 0;
      $stat[7] = $stat['size'] = 0;
      $stat[8] = $stat['atime'] = 0;
      $stat[9] = $stat['mtime'] = 0;
      $stat[10] = $stat['ctime'] = 0;
      $stat[11] = $stat['blksize'] = 0;
      $stat[12] = $stat['blocks'] = 0;

      if (!$metadata['dir']) {
        $stat[4] = $stat['uid'] = 's3fs';
        $stat[7] = $stat['size'] = $metadata['filesize'];
        $stat[8] = $stat['atime'] = $metadata['timestamp'];
        $stat[9] = $stat['mtime'] = $metadata['timestamp'];
        $stat[10] = $stat['ctime'] = $metadata['timestamp'];
      }
      return $stat;
    }
    return FALSE;
  }

  /**
   * Determine whether the $uri is a directory.
   *
   * @param string $uri
   *   The path of the resource to check.
   *
   * @return bool
   *   TRUE if the resource is a directory.
   */
  protected function isDir($uri) {
    $metadata = $this->getS3fsObject($uri);
    return $metadata ? $metadata['dir'] : FALSE;
  }

  /**
   * Try to fetch an object from the metadata cache.
   *
   * If that file isn't in the cache, we assume it doesn't exist.
   *
   * @param string $uri
   *   The uri of the resource to check.
   *
   * @return array|bool
   *   An array if the $uri exists, otherwise FALSE.
   */
  protected function getS3fsObject($uri) {
    // For the root directory, return metadata for a generic folder.
    if ($this->streamWrapperManager::getTarget($uri) == '') {
      return $this->s3fs->convertMetadata('/', []);
    }

    // Trim any trailing '/', in case this is a folder request.
    $uri = rtrim($uri, '/');

    if (mb_strlen($uri) > S3fsServiceInterface::MAX_URI_LENGTH) {
      return FALSE;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $uri)) {
      return FALSE;
    }

    $this->preventCrossSchemeAccess($uri);

    // Check if this URI is in the cache. NOTE We do this even if cache is
    // disabled because directories do not exist in S3, only object keys.
    /** @var array{'uri': string,'filesize': string, 'timestamp': string, 'dir': '0'|'1', 'version': string}|false $metadata */
    $metadata = $this->readCache($uri);

    // If cache ignore is enabled, query S3 for all URIs that are
    // not directories.
    if ($this->shouldLookupMetadataFromBucket(!empty($this->config['ignore_cache']), $metadata)) {
      // If getS3Metadata() returns FALSE, the file doesn't exist.
      $metadata = $this->getS3Metadata($uri);
    }

    return $metadata;
  }

  /**
   * Fetch an object from the file metadata cache table.
   *
   * @param string $uri
   *   The uri of the resource to check.
   *
   * @return array
   *   An array of metadata if the $uri is in the cache. Otherwise, FALSE.
   */
  protected function readCache($uri) {
    $uri = $this->streamWrapperManager->normalizeUri($uri);

    // Cache DB reads so that faster caching mechanisms (e.g. redis, memcache)
    // can further improve performance.
    $cid = S3FS_CACHE_PREFIX . $uri;
    $cache = \Drupal::cache(S3FS_CACHE_BIN);

    if ($cached = $cache->get($cid)) {
      $record = $cached->data;
    }
    else {
      $lock = \Drupal::lock();
      // Cache miss. Avoid a stampede.
      if (!$lock->acquire($cid, 1)) {
        // Another request is building the variable cache. Wait, then re-run
        // this function.
        $lock->wait($cid);
        $record = $this->readCache($uri);
      }
      else {

        $public_folder = !empty($this->config['public_folder']) ? $this->config['public_folder'] : 's3fs-public';
        $public_folder = trim($public_folder, '/');
        $private_folder = !empty($this->config['private_folder']) ? $this->config['private_folder'] : 's3fs-private';
        $private_folder = trim($private_folder, '/');

        $query = \Drupal::database()->select('s3fs_file', 's')
          ->fields('s')
          ->condition('uri', $uri, '=');

        // Limit searching to file under the same scheme.
        switch (StreamWrapperManager::getScheme($uri)) {
          case 's3':
            // Prevent private and public folder from showing nested under
            // s3://.
            $query->condition('uri', 's3://' . $query->escapeLike($public_folder) . '/%', 'NOT LIKE');
            $query->condition('uri', 's3://' . $public_folder, '<>');
            $query->condition('uri', 's3://' . $query->escapeLike($private_folder) . '/%', 'NOT LIKE');
            $query->condition('uri', 's3://' . $private_folder, '<>');
            break;

          case 'public':
            // Prevent the private folder from appearing nested under public://.
            if (mb_strpos($private_folder, $public_folder) === 0) {
              $private_search = '#^' . $private_folder . '#';
              $prefix = preg_replace($private_search, '', $public_folder);
              $query->condition('uri', 'public://' . $query->escapeLike($prefix) . '/%', 'NOT LIKE');
              $query->condition('uri', 'public://' . $prefix, '<>');
            }
            break;

          case 'private':
            // Prevent the public folder from showing up nested under
            // private://.
            if (mb_strpos($public_folder, $private_folder) === 0) {
              $public_search = '#^' . $public_folder . '#';
              $prefix = preg_replace($public_search, '', $private_folder);
              $query->condition('uri', 'private://' . $query->escapeLike($prefix) . '/%', 'NOT LIKE');
              $query->condition('uri', 'private://' . $prefix, '<>');
            }
            break;
        }

        $record = $query->execute()->fetchAssoc();

        $cache->set($cid, $record, Cache::PERMANENT, [S3FS_CACHE_TAG]);
        $lock->release($cid);
      }
    }

    return $record ? $record : FALSE;
  }

  /**
   * Write an object's (and its ancestor folders') metadata to the cache.
   *
   * @param array $metadata
   *   An associative array of file metadata in this format:
   *     'uri' => The full URI of the file, including the scheme.
   *     'filesize' => The size of the file, in bytes.
   *     'timestamp' => The file's create/update timestamp.
   *     'dir' => A boolean indicating whether the object is a directory.
   *
   * @throws \Drupal\s3fs\S3fsException
   *   Exceptions which occur in the database call will percolate.
   */
  protected function writeCache(array $metadata) {
    $metadata['uri'] = $this->streamWrapperManager->normalizeUri($metadata['uri']);

    // Should never happen as we attempt to capture this earlier.
    if (mb_strlen($metadata['uri']) > S3fsServiceInterface::MAX_URI_LENGTH) {
      return;
    }

    // Prohibit objects with UTF8 4-byte characters due to SQL limits.
    // @see https://www.drupal.org/project/s3fs/issues/3266062
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $metadata['uri'])) {
      return;
    }

    \Drupal::database()->merge('s3fs_file')
      ->key('uri', $metadata['uri'])
      ->fields($metadata)
      ->execute();

    // Clear this URI from the Drupal cache, to ensure the next read isn't
    // from a stale cache entry.
    $cid = S3FS_CACHE_PREFIX . $metadata['uri'];
    $cache = \Drupal::cache(S3FS_CACHE_BIN);
    $cache->delete($cid);

    $dirname = \Drupal::service('file_system')->dirname($metadata['uri']);
    // If this file isn't in the root directory, also write this file's
    // ancestor folders to the cache.
    if ($this->streamWrapperManager::getTarget($dirname) != '') {
      $this->mkdir($dirname, NULL, STREAM_MKDIR_RECURSIVE);
    }
  }

  /**
   * Delete an object's metadata from the cache.
   *
   * @param mixed $uri
   *   A string (or array of strings) containing the URI(s) of the object(s)
   *   to be deleted.
   *
   * @throws \Drupal\s3fs\S3fsException
   *   Exceptions which occur in the database call will percolate.
   */
  protected function deleteCache($uri) {
    if (!is_array($uri)) {
      $uri = [$uri];
    }

    $cids = [];

    // Build an OR query to delete all the URIs at once.
    $delete_query = \Drupal::database()->delete('s3fs_file');
    $or = $delete_query->orConditionGroup();
    foreach ($uri as $u) {
      $or->condition('uri', $u, '=');
      // Add URI to cids to be cleared from the Drupal cache.
      $cids[] = S3FS_CACHE_PREFIX . $u;
    }

    // Clear URIs from the Drupal cache.
    $cache = \Drupal::cache(S3FS_CACHE_BIN);
    $cache->deleteMultiple($cids);

    $delete_query->condition($or);
    return $delete_query->execute();
  }

  /**
   * Returns the converted metadata for an object in S3.
   *
   * @param string $uri
   *   The URI for the object in S3.
   *
   * @return array
   *   An array of DB-compatible file metadata or empty array if lookup fails.
   */
  protected function getS3Metadata($uri) {
    $params = $this->getCommandParams($uri);
    try {
      $result = $this->s3->headObject($params);
      $data = $result->toArray();
    }
    catch (S3Exception $e) {
      // headObject() throws this exception if the requested key doesn't exist
      // in the bucket.
      return FALSE;
    }
    catch (\Exception $e) {
      $this->logException($e);
      return FALSE;
    }

    return $this->s3fs->convertMetadata($uri, $data);
  }

  /**
   * Triggers one or more errors.
   *
   * @param string|array $errors
   *   Errors to trigger.
   * @param mixed $flags
   *   If set to STREAM_URL_STAT_QUIET, no error or exception is triggered.
   *
   * @return bool
   *   Always returns FALSE.
   */
  protected function triggerError($errors, $flags = NULL) {
    if ($flags != STREAM_URL_STAT_QUIET) {
      trigger_error(implode("\n", (array) $errors), E_USER_ERROR);
    }
    $this->errorState = TRUE;
    return FALSE;
  }

  /**
   * Helper function to safely append a GET argument to a given base URL.
   *
   * @param string $base_url
   *   The URL onto which the GET arg will be appended.
   * @param string $name
   *   The name of the GET argument.
   * @param string $value
   *   The value of the GET argument. Optional.
   *
   * @return string
   *   The converted path GET argument.
   */
  protected static function appendGetArg($base_url, $name, $value = NULL) {
    $separator = strpos($base_url, '?') === FALSE ? '?' : '&';
    $new_url = "{$base_url}{$separator}{$name}";
    if ($value !== NULL) {
      $new_url .= "=$value";
    }
    return $new_url;
  }

  /**
   * {@inheritdoc}
   *
   * Get the stream's context options or remove them if wanting default.
   *
   * @param bool $removeContextData
   *   Whether to remove the stream's context information.
   *
   * @return array
   *   An array of options.
   */
  private function getOptions($removeContextData = FALSE) {
    // Context is not set when doing things like stat.
    if (is_null($this->context)) {
      $this->context = stream_context_get_default();
    }
    $options = stream_context_get_options($this->context);

    if ($removeContextData) {
      unset($options['client'], $options['seekable'], $options['cache']);
    }

    return $options;
  }

  /**
   * Converts a Drupal URI path into what is expected to be stored in S3.
   *
   * @param string $uri
   *   An appropriate URI formatted like 'protocol://path'.
   * @param bool $prepend_bucket
   *   Whether to prepend the bucket name. S3's stream wrapper requires this for
   *   some functions.
   *
   * @return string
   *   A converted string ready for S3 to process it.
   */
  protected function convertUriToKeyedPath($uri, $prepend_bucket = TRUE) {
    // Remove the protocol.
    $parts = explode('://', $uri);

    // Remove erroneous leading or trailing, forward-slashes and backslashes.
    $parts[1] = trim($parts[1], '\\/');

    if (!empty($parts[1])) {
      // public:// file are all placed in the s3fs_public_folder.
      $public_folder = !empty($this->config['public_folder']) ? $this->config['public_folder'] : 's3fs-public';
      $private_folder = !empty($this->config['private_folder']) ? $this->config['private_folder'] : 's3fs-private';
      if (StreamWrapperManager::getScheme($uri) == 'public') {
        $parts[1] = "$public_folder/{$parts[1]}";
      }
      // private:// file are all placed in the s3fs_private_folder.
      elseif (StreamWrapperManager::getScheme($uri) == 'private') {
        $parts[1] = "$private_folder/{$parts[1]}";
      }

      // If it's set, all files are placed in the root folder.
      if (!empty($this->config['root_folder'])) {
        $parts[1] = "{$this->config['root_folder']}/{$parts[1]}";
      }

      // Prepend the uri with a bucket since AWS SDK expects this.
      if ($prepend_bucket) {
        $parts[1] = $this->config['bucket'] . '/' . $parts[1];
      }
    }

    // Set protocol to S3 so AWS stream wrapper works correctly.
    $parts[0] = 's3';
    return implode('://', $parts);
  }

  /**
   * Return bucket and key for a command array.
   *
   * @param string $uri
   *   Uri to the required object.
   *
   * @return array
   *   A modified path to the key in S3.
   */
  protected function getCommandParams($uri) {
    $convertedPath = $this->convertUriToKeyedPath($uri, FALSE);
    $params = $this->getOptions(TRUE);
    $params['Bucket'] = $this->config['bucket'];
    $params['Key'] = $this->streamWrapperManager::getTarget($convertedPath);

    // Allow other modules to change the command param settings.
    \Drupal::moduleHandler()->alter('s3fs_command_params', $params);

    return $params;
  }

  /**
   * Log an exception (using methods based on Drupal Release version).
   *
   * @param \Exception $e
   *   The exception to log.
   */
  protected function logException(\Exception $e): void {
    if (version_compare(\Drupal::VERSION, '10.1.0', '>=')) {
      $logger = \Drupal::logger('s3fs');
      Error::logException($logger, $e);
    }
    else {
      // @phpstan-ignore-next-line
      watchdog_exception('S3FS', $e);
    }
  }

  /**
   * BC Handler for stream_context_set_option 2 param deprecation.
   *
   * @param resource $context
   *   The S3 context.
   * @param array $options
   *   The options to set.
   */
  private function setStreamContextOptions($context, array $options): void {
    if (function_exists('stream_context_set_options')) {
      stream_context_set_options($context, $options);
    }
    else {
      stream_context_set_option($context, $options);
    }

  }

}
