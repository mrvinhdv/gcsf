<?php


namespace Drupal\gcsf\StreamWrapper;


class PublicGcsfStream extends GcsfStream {

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->t('Public files (gcs)');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Public files served from GCS.');
  }
}
