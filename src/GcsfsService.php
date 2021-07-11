<?php

namespace Drupal\gcsfs;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\Entity\File;
use Google\Cloud\Storage\StorageClient;

/**
 * Class GcsfsServiceService.
 */
class GcsfsService implements GcsfsServiceInterface {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal\Component\Datetime\TimeInterface definition.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $datetimeTime;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $gcsConfig;

  /**
   * Drupal\Core\StreamWrapper\StreamWrapperManagerInterface definition
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Constructs a new GcsfsService object.
   */
  public function __construct(Connection $database,
                              ConfigFactoryInterface $config_factory,
                              TimeInterface $datetime_time,
                              StreamWrapperManagerInterface $streamWrapperManager) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->datetimeTime = $datetime_time;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->gcsConfig = $config_factory->get('gcsfs.setting');
  }

  /**
   * {@inheritdoc}
   */
  public function getClientGcs(array $config) {
    $gcs = &drupal_static(__METHOD__ . '_GcsClient');
    $static_config = &drupal_static(__METHOD__ . '_static_config');

    if (!isset($s3) || $static_config != $config) {
      $client_config = [];
      if (!isset($config['gcs_project_id'])) {
        $config['gcs_project_id'] = $this->gcsConfig->get('gcs_project_id');
      }
      if (isset($config['gcs_json_credential'])) {
        $config['gcs_json_credential'] = $this->gcsConfig->get('gcs_json_credential');
      }
      if (isset($config['gcs_bucket'])) {
        $config['gcs_bucket'] = $this->gcsConfig->get('gcs_bucket');
      }
      $fid = $this->gcsConfig->get('gcs_json_credential');

      /** @var \Drupal\file\FileInterface $file */
      $file = File::load($fid);

      /** @var \Drupal\Core\File\FileSystemInterface $key_file_path */
      $key_file_path = \Drupal::service('file_system')
        ->realpath($file->getFileUri());
      $client_config['projectId'] = $config['gcs_project_id'];
      $client_config['keyFilePath'] = $key_file_path;
      try {
        // Create the Google\Cloud\Storage\StorageClient.
        $gcs = new StorageClient($client_config);
      } catch (\Exception $e) {
        //todo write log
      }
      $static_config = $config;
    }
    return $gcs;
  }

  /**
   * {@inheritdoc}
   */
  public function getGcsFileInfo($uri) {
    $query = $this->database->select('gcsfs_file', 'gcs');
    $query
      ->condition('uri', $uri)
      ->fields('gcs');
    $result = $query->execute();
    return $result->fetchAssoc();

  }

  /**
   * {@inheritdoc}
   */
  public function readCache($uri) {
    $uri = $this->streamWrapperManager->normalizeUri($uri);

    // Cache DB reads so that faster caching mechanisms (e.g. redis, memcache)
    // can further improve performance.
    $cid = GCSFS_CACHE_PREFIX . $uri;
    $cache = \Drupal::cache(GCSFS_CACHE_BIN);

    if ($cached = $cache->get($cid)) {
      $record = $cached->data;
    }
    else {
      $lock = \Drupal::lock();

      if (!$lock->acquire($cid, 1)) {
        // Another request is building the variable cache. Wait, then re-run
        // this function.
        $lock->wait($cid);
        $record = $this->readCache($uri);
      }
      else {
        $record = $this->database->select('gcsfs_file', 'gcs')
          ->fields('gcs')
          ->condition('uri', $uri, '=')
          ->execute()
          ->fetchAssoc();

        $cache->set($cid, $record, Cache::PERMANENT, [GCSFS_CACHE_TAG]);
        $lock->release($cid);
      }
    }
    return $record ? $record : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function writeCache($uri, array $file_info) {
    $file_info['uri'] = $this->streamWrapperManager->normalizeUri($uri);
    $this->database->merge('gcsfs_file')->key(['uri' => $file_info['uri']])
      ->fields($file_info)
      ->execute();

    // Clear this URI from the Drupal cache, to ensure the next read isn't
    // from a stale cache entry.
    $cid = GCSFS_CACHE_PREFIX . $file_info['uri'];
    $cache = \Drupal::cache(GCSFS_CACHE_BIN);
    $cache->delete($cid);

    $dirname = \Drupal::service('file_system')->dirname($file_info['uri']);
    if (StreamWrapperManager::getTarget($dirname) != '') {
      \Drupal::service('stream_wrapper.gcsfs')
        ->mkdir($dirname, NULL, STREAM_MKDIR_RECURSIVE);
    }
  }


  /**
   * {@inheritdoc}
   */
  public function deleteCache($uri) {
    if (!is_array($uri)) {
      $uri = [$uri];
    }

    $cids = [];
    // Build an OR query to delete all the URIs at once.
    $delete_query = $this->database->delete('gcsfs_file');
    $or = $delete_query->orConditionGroup();
    foreach ($uri as $u) {
      $or->condition('uri', $u, '=');
      // Add URI to cids to be cleared from the Drupal cache.
      $cids[] = GCSFS_CACHE_PREFIX . $u;
    }

    // Clear URIs from the Drupal cache.
    $cache = \Drupal::cache(GCSFS_CACHE_BIN);
    $cache->deleteMultiple($cids);

    $delete_query->condition($or);
    return $delete_query->execute();
  }

  public function convertMetadata($uri, array $file_info) {
    $metadatas = [];
    if ($file_info) {
      $metadatas['uri'] = $uri;
      $metadatas['filesize'] = $file_info['size'];
      $metadatas['filemime'] = $file_info['contentType'];
    }
    return $metadatas;
  }

}
