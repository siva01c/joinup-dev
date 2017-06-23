<?php

namespace Drupal\joinup_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Base plugin for files migration.
 *
 * @MigrateSource(
 *   id = "file"
 * )
 */
class File extends JoinupSqlBase {

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['fid' => ['type' => 'string']];
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'fid' => $this->t('File ID'),
      'path' => $this->t('File path'),
      'timestamp' => $this->t('Created time'),
      'uid' => $this->t('File owner'),
      'destination_uri' => $this->t('Destination URI'),
      'numeric_fid' => $this->t('Numeric file ID'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $table = 'd8_file_' . $this->migration->getDerivativeId();
    return $this->select($table)
      ->fields($table)
      // Extra precaution for views that are returning also non-file records.
      // @see web/modules/custom/joinup_migrate/fixture/1.file_documentation.sql
      ->isNotNull("$table.fid");
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Assure a full-qualified path for managed files.
    $fid = $row->getSourceProperty('fid');
    if (ctype_digit($fid)) {
      $source_path = $this->getLegacySiteFiles() . '/' . $row->getSourceProperty('path');
      $row->setSourceProperty('path', $source_path);
      // If there's a migrated managed file, we use this to assign the same FID.
      $row->setSourceProperty('numeric_fid', (int) $fid);
    }

    return parent::prepareRow($row);
  }

}
