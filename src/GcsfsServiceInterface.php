<?php

namespace Drupal\gcsfs;

/**
 * Interface GcsfsInterface.
 */
interface GcsfsServiceInterface {


  /**
   * {@inheritdoc}
   */
  public function getClientGcs(array $config);

  /**
   * {@inheritdoc}
   */
  public function getGcsFileInfo($uri);

  /**
   * {@inheritdoc}
   */
  public function readCache($uri);

  /**
   * {@inheritdoc}
   */
  public function writeCache($uri, array $file_info);

  /**
   * {@inheritdoc}
   */
  public function deleteCache($uri);

  /**
   * Convert file info gcs.
   * @param array $file_info
   *
   * @return mixed
   */
  public function convertMetadata($uri, array $file_info);


}
