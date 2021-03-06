<?php

declare(strict_types = 1);

namespace Drupal\joinup\Context;

use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\DrupalExtension\Hook\Scope\BeforeNodeCreateScope;
use Drupal\joinup\Traits\FileTrait;
use Drupal\joinup\Traits\NodeTrait;
use Drupal\joinup\Traits\TraversingTrait;
use PHPUnit\Framework\Assert;

/**
 * Behat step definitions for testing documents.
 */
class JoinupDocumentContext extends RawDrupalContext {

  use FileTrait;
  use NodeTrait;
  use TraversingTrait;

  /**
   * Navigates to the canonical page display of a document.
   *
   * @param string $title
   *   The name of the document.
   *
   * @When I go to the :title document
   * @When I visit the :title document
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function visitDocument($title) {
    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->getNodeByTitle($title, 'document');
    $this->visitPath($node->toUrl()->toString());
  }

  /**
   * Handles the file field for document nodes.
   *
   * @param \Drupal\DrupalExtension\Hook\Scope\BeforeNodeCreateScope $scope
   *   An object containing the entity properties and fields that are to be used
   *   for creating the node as properties on the object.
   *
   * @BeforeNodeCreate
   *
   * @throws \Exception
   *   Thrown when an attached file cannot be found or cannot be saved in the
   *   Drupal public files folder.
   */
  public static function massageDocumentFieldsBeforeNodeCreate(BeforeNodeCreateScope $scope): void {
    $node = $scope->getEntity();

    if ($node->type !== 'document') {
      return;
    }

    if (!empty($node->field_file)) {
      $type = isset($node->{'file type'}) ? $node->{'file type'} : 'remote';
      if ($type !== 'remote') {
        // If the file is local we want to copy it from the fixtures into the
        // file system and register it in the DocumentSubContext so it can be
        // cleaned up after the scenario ends. Perform a small dance to get
        // access to the context class from inside this static callback.
        /** @var \Behat\Behat\Context\Environment\InitializedContextEnvironment $environment */
        $environment = $scope->getEnvironment();
        /** @var \Drupal\joinup\Context\JoinupDocumentContext $context */
        $context = $environment->getContext(self::class);
        $node->field_file = $context->createFile($node->field_file)->id();
      }

      unset($node->{'file type'});
    }

    if (isset($node->field_document_publication_date)) {
      $time = $node->field_document_publication_date;
      if (!is_numeric($time)) {
        $time = strtotime($time);
        if (empty($time)) {
          throw new \Exception("{$node->field_document_publication_date} could not be converted to timestamp.");
        }
      }

      /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
      $date_formatter = \Drupal::service('date.formatter');
      $node->field_document_publication_date = $date_formatter->format($time, 'custom', 'Y-m-d H:i:s');
    }
  }

  /**
   * Asserts that a document tile has a link to download its attached file.
   *
   * @param string $title
   *   The document title.
   *
   * @throws \Exception
   *   Thrown when the download link is not found.
   *
   * @Then the download link is shown in the :title document tile
   */
  public function assertDocumentTileHasDownloadLink($title) {
    $tile = $this->getTileByHeading($title);

    $file_url_service = \Drupal::service('file_url.handler');
    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->getNodeByTitle($title, 'document');
    /** @var \Drupal\file\FileInterface $file */
    $file = $file_url_service::urlToFile($node->get('field_file')->first()->target_id);

    $link = $tile->findLink('Download');
    if (!$link) {
      throw new \Exception("No download link found in the '$title' tile.");
    }

    // @see \Drupal\file_url\Plugin\Field\FieldFormatter\FileUrlFormatter::viewElements()
    $expected_href = file_url_transform_relative(file_create_url($file->getFileUri()));
    Assert::assertEquals($expected_href, $link->getAttribute('href'), 'The download link is not pointing to the correct file.');
  }

}
