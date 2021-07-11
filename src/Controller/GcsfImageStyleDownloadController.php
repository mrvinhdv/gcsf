<?php

namespace Drupal\gcsf\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\image\Controller\ImageStyleDownloadController;
use Drupal\image\ImageStyleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Defines a controller to serve public image styles.
 */
class GcsfImageStyleDownloadController extends ImageStyleDownloadController {

  /**
   * Generates a Amazon S3 derivative, given a style and image path.
   *
   * After generating an image, redirect it to the requesting agent. Only used
   * for public or s3 schemes. Private scheme use the normal workflow:
   * \Drupal\image\Controller\ImageStyleDownloadController::deliver().
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $scheme
   *   The file scheme.
   * @param \Drupal\image\ImageStyleInterface $image_style
   *   The image style to deliver.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse|\Symfony\Component\HttpFoundation\Response
   *   The redirect response or some error response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the user does not have access to the file.
   * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException
   *   Thrown when the file is still being generated.
   *
   * @see \Drupal\image\Controller\ImageStyleDownloadController::deliver()
   */
  public function deliver(Request $request, $scheme, ImageStyleInterface $image_style) {
    $target = $request->query->get('file');
    $image_uri = $scheme . '://' . $target;

    // Check that the style is defined, the scheme is valid, and the image
    // derivative token is valid. Sites which require image derivatives to be
    // generated without a token can set the
    // 'image.settings:allow_insecure_derivatives' configuration to TRUE to
    // bypass the latter check, but this will increase the site's vulnerability
    // to denial-of-service attacks. To prevent this variable from leaving the
    // site vulnerable to the most serious attacks, a token is always required
    // when a derivative of a style is requested.
    // The $target variable for a derivative of a style has
    // styles/<style_name>/... as structure, so we check if the $target variable
    // starts with styles/.
    $valid = !empty($image_style) && \Drupal::service('file_system')->validScheme($scheme);
    if (!$this->config('image.settings')->get('allow_insecure_derivatives') || strpos(ltrim($target, '\/'), 'styles/') === 0) {
      $valid &= $request->query->get(IMAGE_DERIVATIVE_TOKEN) === $image_style->getPathToken($image_uri);
    }
    if (!$valid) {
      throw new AccessDeniedHttpException();
    }

    $derivative_uri = $image_style->buildUri($image_uri);

    // Private scheme use:
    // \Drupal\image\Controller\ImageStyleDownloadController::deliver()
    // instead of this.
    if ($scheme == 'private') {
      throw new AccessDeniedHttpException();
    }

    // Don't try to generate file if source is missing.
    if (!file_exists($image_uri)) {
      // If the image style converted the extension, it has been added to the
      // original file, resulting in filenames like image.png.jpeg. So to find
      // the actual source image, we remove the extension and check if that
      // image exists.
      $path_info = pathinfo($image_uri);
      $converted_image_uri = $path_info['dirname'] . DIRECTORY_SEPARATOR . $path_info['filename'];
      if (!file_exists($converted_image_uri)) {
        $this->logger->notice('Source image at %source_image_path not found while trying to generate derivative image at %derivative_path.',
          ['%source_image_path' => $image_uri, '%derivative_path' => $derivative_uri]
        );
        return new Response($this->t('Error generating image, missing source file.'), 404);
      }
      else {
        // The converted file does exist, use it as the source.
//        $image_uri = $converted_image_uri;
        $image_uri = $derivative_uri;
      }
    }

    // Try to generate the image, unless another thread just did it while we
    // were acquiring the lock.
    $success = file_exists($derivative_uri);

    if (!$success) {
      // If we successfully generate the derivative, wait until S3 acknowledges
      // its existence. Otherwise, redirecting to it may cause a 403 error.
      $success = $image_style->createDerivative($image_uri, $derivative_uri);
    }

    if ($success) {
      // Perform a 302 Redirect to the new image derivative in GCS.
      // It must be TrustedRedirectResponse for external redirects.
      $response = new TrustedRedirectResponse(file_create_url($derivative_uri));
      $cacheableMetadata = $response->getCacheableMetadata();
      $cacheableMetadata->addCacheContexts(
        [
          'url.query_args:file',
          'url.query_args:itok',
        ]
      );
      $cacheableMetadata->setCacheMaxAge((int)$this->config('gcsfs.settings')->get('redirect_styles_ttl'));
      $response->addCacheableDependency($cacheableMetadata);
      return $response;
    }
    else {
      $this->logger->notice('Unable to generate the derived image located at %path.', ['%path' => $derivative_uri]);
      return new Response($this->t('Error generating image.'), 500);
    }
  }

}
