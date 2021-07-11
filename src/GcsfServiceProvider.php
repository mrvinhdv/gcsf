<?php

namespace Drupal\gcsf;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;

/**
 * The stream wrapper class.
 *
 * In the docs for this class, anywhere you see "<scheme>", it can mean either
 * "s3" or "public", depending on which stream is currently being serviced.
 */
class GcsfServiceProvider extends ServiceProviderBase {

  /**
   * Modifies existing service definitions.
   *
   * @param ContainerBuilder $container
   *   The ContainerBuilder whose service definitions can be altered.
   */
  public function alter(ContainerBuilder $container) {
    // Replace the public stream wrapper with GcsfsStream.
    if(Settings::get('gcs.use_file_public')) {
      $container->getDefinition('stream_wrapper.public')
        ->setClass('Drupal\gcsf\StreamWrapper\PublicGcsfsStream');
    }
  }


}
