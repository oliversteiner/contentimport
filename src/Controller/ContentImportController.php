<?php
/**
 * @file
 * Contains \Drupal\contentimport\src\Controller\ContentImportController.
 */

namespace Drupal\contentimport\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\system\FileDownloadController;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

/**
 * Controller routines for contentimport routes.
 */
class ContentImportController extends ControllerBase {

  /**
   * Get All Content types.
  */

  public static function getAllContentTypes() {
    $contentTypes = \Drupal::service('entity.manager')->getStorage('node_type')->loadMultiple();
    $contentTypesList = [];
    foreach ($contentTypes as $contentType) {
        $contentTypesList[$contentType->id()] = $contentType->label();
    }
    return $contentTypesList;
  }  
}