<?php

namespace Drupal\gcsfs\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StreamWrapper;

/**
 * Class GcsfStream.
 */
class GcsfStream extends StreamWrapper implements StreamWrapperInterface {

  use StringTranslationTrait;

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
   * The actual bucket name this file's URI is referring to.
   *
   * @var String
   */
  protected $bucket;


  /**
   * The Google Cloud SDK for PHP StorageClient object.
   *
   * @var \Google\Cloud\Storage\StorageClient
   */
  protected $gcs = NULL;

  /**
   * The Gcsfs Service.
   *
   * @var \Drupal\gcsf\GcsfsServiceInterface
   */
  protected $gcsfs = NULL;


  /**
   * Bool HTTPS flag.
   */
  protected $https = FALSE;


  /**
   * The opened protocol (e.g., "gs").
   *
   * @var string
   */
  private $protocol = 'gs';

  /**
   * Domain we use to access files over http.
   *
   * @var string
   */
  protected $domain = 'storage.googleapis.com';


  /**
   * List of directories.
   */
  protected $directories;


  /** @var null */
  protected $streamData = NULL;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $drupalLogger;


  /**
   * Constructs a new GcsfsStream object.
   */
  public function __construct() {
    $settings = &drupal_static('GcsfsStream_constructed_settings');
    if ($settings !== NULL) {
      $this->config = $settings['config'];
      $this->gcsfs = $settings['gcsf'];
      $this->gcs = $settings['gcs'];
      $this->register($this->gcs);
      return;
    }

    // @todo Use dependency injection
    $this->gcsfs = \Drupal::service('gcsf');
    $config = \Drupal::config('gcsf.setting');
    $this->drupalLogger = \Drupal::service('logger.dblog');

    foreach ($config->get() as $prop => $value) {
      $this->config[$prop] = $value;
    }

    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== FALSE) {
      $this->https = TRUE;
    }

    $this->gcs = $this->gcsfs->getClientGcs($this->config);
    $this->register($this->gcs);
    $this->context = stream_context_get_default();
    stream_context_set_option($this->context, 'gs', 'flush', TRUE);


    $settings['config'] = $this->config;
    $settings['gcsfs'] = $this->gcsfs;
    $settings['gcs'] = $this->gcs;
  }

  /**
   * Returns the type of stream wrapper.
   *
   * @return int
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * Returns the name of the stream wrapper for use in the UI.
   *
   * @return string
   *   The stream wrapper name.
   */
  public function getName() {
    $this->t('GCS File system');
  }

  /**
   * Returns the description of the stream wrapper for use in the UI.
   *
   * @return string
   *   The stream wrapper description.
   */
  public function getDescription() {
    $this->t('Google cloud storage');
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
   * Sets the absolute stream resource URI.
   *
   * This allows you to set the URI. Generally is only called by the factory
   * method.
   *
   * @param string $uri
   *   A string containing the URI that should be used for this instance.
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

  /**
   * Returns the stream resource URI.
   *
   * @return string
   *   Returns the current URI of the instance.
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * Returns canonical, absolute path of the resource.
   *
   * Implementation placeholder. PHP's realpath() does not support stream
   * wrappers. We provide this as a default so that individual wrappers may
   * implement their own solutions.
   *
   * @return string
   *   Returns a string with absolute pathname on success (implemented
   *   by core wrappers), or FALSE on failure or if the registered
   *   wrapper does not provide an implementation.
   */
  public function realpath() {
    return FALSE;

  }

  /**
   * Returns a web accessible URL for the resource.
   *
   * This function should return a URL that can be embedded in a web page
   * and accessed from a browser. For example, the external URL of
   * "youtube://xIpLd0WQKCY" might be
   * "http://www.youtube.com/watch?v=xIpLd0WQKCY".
   *
   * @return string
   *   Returns a string containing a web accessible URL for the resource.
   */
  public function getExternalUrl() {
    //get fileinfo from the database
    $fileinfo = $this->getFileObject($this->uri);
    if (!$fileinfo) {
      $target = $this->getTarget($this->uri, FALSE);
      $path_parts = explode('/', $target);
      // if its a request for an image style, the image may need to be created
      if ($path_parts[0] == 'styles') {
        // Check if image exists on Google Storage
        if (!isset($this->gcs)) {
          $this->gcsfs->getClientGcs();
        }
        array_unshift($path_parts, 'gs', 'files');
        $path = implode('/', $path_parts);
        return $GLOBALS['base_url'] . '/' . UrlHelper::encodePath($path);
      }
    }

    $url = NULL;
    $target = $this->getTarget($this->uri);
    if ($target) {
      try {
        $file_info = explode('/', $target);
        $file_name = rawurlencode(array_pop($file_info));
        $file_path = implode('/', $file_info) . '/' . $file_name;
        $secure = 'http://';
        if ($this->https) {
          $secure = 'https://';
        }
        $bucket_base_url = $secure . $this->config['gcs_bucket'] . '.' . $this->domain;
        $url = $bucket_base_url . '/' . $target;
      } catch (\Exception $e) {
        $this->drupalLogger->error('Gcs ExternalUrl:' . $e->getMessage());
      }
    }
    return $url;
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
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->setUri($uri);
    $target = $this->getTarget($this->uri);
    $path = $this->protocol . '://' . $this->config['gcs_bucket'] . '/' . $target;
    return parent::stream_open($path, $mode, $options, $opened_path);
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
  public function stream_lock($operation) {
    return FALSE;
  }


  /**
   * Support for fwrite(), file_put_contents() etc.
   *
   * @param $data
   *   The string to be written.
   *
   * @return
   *   The number of bytes written (integer).
   *
   * @see http://php.net/manual/en/streamwrapper.stream-write.php
   */
  public function stream_write($data) {
    $this->streamData = strlen($data);
    return parent::stream_write($data);
  }


  /**
   * {@inheritdoc}
   *
   * Support for fflush(). Flush current cached stream data to a file in gcs.
   *
   * @return bool
   *   TRUE if data was successfully stored in gcs.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-flush.php
   */
  public function stream_flush() {
    $xxx = '1';
    if (parent::stream_flush()) {
      // Prepare upload parameters.
      $file_info = $this->getFileObject($this->uri);
      $this->gcsfs->writeCache($this->uri, $file_info);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Sets metadata on the stream.
   *
   * @param string $path
   *   A string containing the URI to the file to set metadata on.
   * @param int $option
   *   One of:
   *   - STREAM_META_TOUCH: The method was called in response to touch().
   *   - STREAM_META_OWNER_NAME: The method was called in response to chown()
   *     with string parameter.
   *   - STREAM_META_OWNER: The method was called in response to chown().
   *   - STREAM_META_GROUP_NAME: The method was called in response to chgrp().
   *   - STREAM_META_GROUP: The method was called in response to chgrp().
   *   - STREAM_META_ACCESS: The method was called in response to chmod().
   * @param mixed $value
   *   If option is:
   *   - STREAM_META_TOUCH: Array consisting of two arguments of the touch()
   *     function.
   *   - STREAM_META_OWNER_NAME or STREAM_META_GROUP_NAME: The name of the owner
   *     user/group as string.
   *   - STREAM_META_OWNER or STREAM_META_GROUP: The value of the owner
   *     user/group as integer.
   *   - STREAM_META_ACCESS: The argument of the chmod() as integer.
   *
   * @return bool
   *   Returns TRUE on success or FALSE on failure. If $option is not
   *   implemented, FALSE should be returned.
   *
   * @see http://php.net/manual/streamwrapper.stream-metadata.php
   */
  public function stream_metadata($path, $option, $value) {
    $bypassed_options = [STREAM_META_ACCESS];
    return in_array($option, $bypassed_options);
  }

  /**
   * Change stream options.
   *
   * This method is called to set options on the stream.
   *
   * @param int $option
   *   One of:
   *   - STREAM_OPTION_BLOCKING: The method was called in response to
   *     stream_set_blocking().
   *   - STREAM_OPTION_READ_TIMEOUT: The method was called in response to
   *     stream_set_timeout().
   *   - STREAM_OPTION_WRITE_BUFFER: The method was called in response to
   *     stream_set_write_buffer().
   * @param int $arg1
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: The requested blocking mode:
   *     - 1 means blocking.
   *     - 0 means not blocking.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in seconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The buffer mode, STREAM_BUFFER_NONE or
   *     STREAM_BUFFER_FULL.
   * @param int $arg2
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: This option is not set.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in microseconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The requested buffer size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise. If $option is not implemented, FALSE
   *   should be returned.
   */
  public function stream_set_option($option, $arg1, $arg2) {
    return FALSE;
  }


  /**
   * Truncate stream.
   *
   * Will respond to truncation; e.g., through ftruncate().
   *
   * @param int $new_size
   *   The new size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function stream_truncate($new_size) {
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
   *   FALSE if the call to Gcs failed, in which case the file will not be
   *   removed from the cache.
   *
   * @see http://php.net/manual/en/streamwrapper.unlink.php
   */
  public function unlink($uri) {
    $this->setUri($uri);
    $target = $this->getTarget($uri);
    $path = $this->protocol . '://' . $this->config['gcs_bucket'] . '/' . $target;
    if (parent::unlink($path)) {
      // remove file from database
      $this->gcsfs->deleteCache($this->uri);
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
    if (parent::rename($this->getTarget($from_uri), $this->getTarget($to_uri))) {
      $metadata = $this->gcsfs->readCache($from_uri);
      $metadata['uri'] = $to_uri;
      $this->gcsfs->writeCache($this->uri, $metadata);
      $this->gcsfs->deleteCache($from_uri);
      return TRUE;
    }
    return FALSE;
  }


  /**
   * Gets the name of the directory from a given path.
   *
   * This method is usually accessed through
   * \Drupal\Core\File\FileSystemInterface::dirname(), which wraps around the
   * normal PHP dirname() function, which does not support stream wrappers.
   *
   * @param string $uri
   *   An optional URI.
   *
   * @return string
   *   A string containing the directory name, or FALSE if not applicable.
   *
   * @see \Drupal\Core\File\FileSystemInterface::dirname()
   */
  public function dirname($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }
    list($scheme, $target) = explode('://', $uri, 2);
    $target = $this->getTarget($uri);
    $dirname = dirname($target);

    if ($dirname == '.') {
      $dirname = '';
    }
    return $scheme . '://' . $dirname;
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
    // Some Drupal plugins call mkdir with a trailing slash. We mustn't store
    // that slash in the cache.
    $uri = rtrim($uri, '/');
    // If this URI already exists in the cache, return TRUE if it's a folder
    // (so that recursive calls won't improperly report failure when they
    // reach an existing ancestor), or FALSE if it's a file (failure).
    // If the STREAM_MKDIR_RECURSIVE option was specified, also create all the
    // ancestor folders of this uri, except for the root directory.
    $parent_dir = \Drupal::service('file_system')->dirname($uri);
    if (($options & STREAM_MKDIR_RECURSIVE) && StreamWrapperManager::getTarget($parent_dir) != '') {
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
    if (parent::rmdir($uri, $options)) {
      $this->gcsfs->deleteCache($uri);
      return TRUE;
    }
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
   *   GcsfsStreamWrapper ignores this value.
   *
   * @return array
   *   An array with file status, or FALSE in case of an error.
   *
   * @see http://php.net/manual/en/streamwrapper.url-stat.php
   */
  public function url_stat($uri, $flags) {
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
  public function dir_opendir($uri, $options = NULL) {
    if (!$this->_uri_is_dir($uri)) {
      return FALSE;
    }

    $target = $this->getTarget($uri);

    $ls_options = [
      'delimiter' => '/',
    ];
    if (!empty($target)) {
      $ls_options['prefix'] = "{$target}/";
    }

    $directories = $this->gcs->bucket($this->config['gcs_bucket']);
    $this->directories = [];
    if (isset($directories->items)) {
      foreach ($directories->items as $item) {
        if ($item['name'] !== "{$target}/") {
          $this->directories[] = basename($item['name']);
        }
      }
    }

    // Prefixes array returns the list of directories.
    if (isset($directories->prefixes)) {
      foreach ($directories->prefixes as $item) {
        $this->directories[] = rtrim(str_replace("{$target}/", '', $item), '/\\');
      }
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
  public function dir_readdir() {
    $current = current($this->directories);
    if ($current) {
      next($this->directories);
    }
    return ($current) ? $current : FALSE;
  }

  /***************************************************************************
   * Internal Functions
   ***************************************************************************/

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
    $target = $this->getTarget($uri);
    if ($target) {
      $fileinfo = $this->gcsfs->getGcsFileInfo($uri);
      if (!$fileinfo) {
        $is_dir = $this->_uri_is_dir($this->uri);
        if ($is_dir) {
          $bucket = $this->gcs->bucket($this->config['gcs_bucket']);
          $options = ['prefix' => $target];
          if (!$bucket->objects($options)->valid()) {
            $mode = 0040000;
            $size = 1;
            $mode |= 0777;
            $stat = $this->createFileMode($mode, $size);
            return $stat;
          }
          return FALSE;
        }

        $bucket = $this->gcs->bucket($this->config['gcs_bucket']);
        $response = $bucket->object($target);
        if ($response->exists()) {
          $mode = 0100000;
          $size = $this->streamData;
          $mode |= 0777;
          $created = strtotime('now');
          $stat = $this->createFileMode($mode, $size, $created);
          return $stat;
        }
        else {
          $mode = 0100000;
          $size = $this->streamData;
          $mode |= 0777;
          $created = strtotime('now');
          $stat = $this->createFileMode($mode, $size, $created);
          return $stat;
        }
      }
      else {
        // fileinfo exists in the database
        $mode = 0100000;
        $filesize = $fileinfo['filesize'];
        $created = $fileinfo['timestamp'];
        $mode |= 0777;
        $stat = $this->createFileMode($mode, $filesize, $created);
        return $stat;
      }
    }
    return FALSE;
  }


  /**
   * Returns the local writable target of the resource within the stream.
   *
   * Also sets the bucket field by parsing the scheme.
   *
   * @param $uri
   *   Optional URI.
   *
   * @param $actual_bucket_name
   *   Optional If the bucket name should be the actual name.
   *
   * @return
   *   Returns a string representing a location suitable for writing of a file,
   *   or FALSE if unable to write to the file such as with read-only streams.
   */
  protected function getTarget($uri = NULL, $actual_bucket_name = TRUE) {
    if (empty($uri)) {
      $uri = $this->uri;
    }
    if (!empty($uri)) {
      list($scheme, $target) = explode('://', $uri, 2);

      if (empty($this->config['gcs_bucket'])) {
        $parts = explode(".", $scheme);
        unset($parts[0]);
        $bucket = implode('.', $parts);
        $this->bucket = str_replace('+', '-', $bucket);
      }

      if ($actual_bucket_name) {
        // Convert bucket to actual naming for image style folders.
        $path = explode('/', $target);
        if ($path[0] == 'styles') {
          $path[2] = str_replace('+', '-', $path[2]);
          $target = implode('/', $path);
        }
      }
      // Remove erroneous leading or trailing, forward-slashes and backslashes.
      return trim($target, '\/');
    }

    return FALSE;
  }


  /**
   * Check if uri is a folder.
   *
   * @param string $uri
   *   The uri to the file.
   *
   * @return array
   *   TRUE if it is a folder.
   */
  protected function _uri_is_dir($uri) {
    $path_info = pathinfo($this->uri);
    return !isset($path_info['extension']) ? TRUE : FALSE;
  }

  /**
   * Creates the fstat array.
   *
   * @param int $mode
   *   The file mode.
   * @param int $size
   *   The file size.
   * @param int $created
   *   The file created timestamp.
   *
   * @return array
   *   An array with fstat info populated or empty.
   */
  protected function createFileMode($mode, $size, $created = 0) {
    $stat = [];

    $stat[0] = $stat['dev'] = 0;
    $stat[1] = $stat['ino'] = 0;
    $stat[2] = $stat['mode'] = $mode;
    $stat[3] = $stat['nlink'] = 0;
    $stat[4] = $stat['uid'] = 0;
    $stat[5] = $stat['gid'] = 0;
    $stat[6] = $stat['rdev'] = 0;
    $stat[7] = $stat['size'] = $size;
    $stat[8] = $stat['atime'] = $created;
    $stat[9] = $stat['mtime'] = $created;
    $stat[10] = $stat['ctime'] = $created;
    $stat[11] = $stat['blksize'] = 0;
    $stat[12] = $stat['blocks'] = 0;
    $stat[4] = $stat['uid'] = 0;
    $stat[7] = $stat['size'] = $size;
    $stat[8] = $stat['atime'] = $created;
    $stat[9] = $stat['mtime'] = $created;
    $stat[10] = $stat['ctime'] = $created;

    return $stat;
  }


  /**
   * {@inheritdoc}
   *
   * Return object Gcs
   */
  protected function getFileObject($uri) {
    $uri = rtrim($uri, '/');
    $cache_enabled = empty($this->config['gcs_cache']);
    $fileinfo = [];
    if ($cache_enabled) {
      $fileinfo = $this->gcsfs->readCache($uri);
    }

    if (!$fileinfo) {
      $target = $this->getTarget($uri);
      $bucket = $this->gcs->bucket($this->config['gcs_bucket']);
      $is_exits = $bucket->object($target)->exists();
      if ($is_exits) {
        $fileinfo = $this->gcsfs->convertMetadata($this->uri, $bucket->object($target)
          ->info());
        if (!empty($fileinfo) && $cache_enabled) {
          $this->gcsfs->writeCache($uri, $fileinfo);
        }
      }
      else {
        $fileinfo = $this->gcsfs->getGcsFileInfo($uri);
      }
    }
    return $fileinfo;
  }


  /**
   * {@inheritdoc]
   */
  public static function register(StorageClient $client, $protocol = 'gs') {
    parent::register($client, $protocol);
  }

}
