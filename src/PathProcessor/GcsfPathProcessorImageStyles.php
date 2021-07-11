<?php


namespace Drupal\gcsf\PathProcessor;


use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

class GcsfPathProcessorImageStyles implements InboundPathProcessorInterface{


  const IMAGE_STYLE_PATH_PREFIX = '/gs/files/styles/';

  /**
   * Processes the inbound path.
   *
   * Implementations may make changes to the request object passed in but should
   * avoid all other side effects. This method can be called to process requests
   * other than the current request.
   *
   * @param string $path
   *   The path to process, with a leading slash.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HttpRequest object representing the request to process. Note, if this
   *   method is being called via the path_processor_manager service and is not
   *   part of routing, the current request object must be cloned before being
   *   passed in.
   *
   * @return string
   *   The processed path.
   */
  public function processInbound($path, Request $request) {
    if ($this->isImageStylePath($path)) {
      // Strip out path prefix.
      $rest = preg_replace('|^' . preg_quote(static::IMAGE_STYLE_PATH_PREFIX, '|') . '|', '', $path);

      // Get the image style, scheme and path.
      if (substr_count($rest, '/') >= 2) {
        list($image_style, $scheme, $file) = explode('/', $rest, 3);

        if ($this->isValidScheme($scheme)) {
          // Set the file as query parameter.
          $request->query->set('file', $file);
          $path = static::IMAGE_STYLE_PATH_PREFIX . $image_style . '/' . $scheme;
        }
      }
    }

    return $path;
  }

  /**
   * Check if scheme is gcs image style supported.
   *
   * @param $scheme
   * @return bool
   */
  private function isValidScheme($scheme) {
    return in_array($scheme, ['public', 'gs']);
  }

  /**
   * Check if the path is a gcs image style path.
   *
   * @param $path
   * @return bool
   */
  private function isImageStylePath($path) {
    return strpos($path, static::IMAGE_STYLE_PATH_PREFIX) === 0;
  }

}
