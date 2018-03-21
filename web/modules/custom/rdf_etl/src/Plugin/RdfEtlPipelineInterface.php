<?php

declare(strict_types = 1);

namespace Drupal\rdf_etl\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\rdf_etl\RdfEtlStepList;

/**
 * Defines an interface for Data pipeline plugins.
 */
interface RdfEtlPipelineInterface extends PluginInspectionInterface {

  /**
   * Gets the sequence in which the data processing steps should be performed.
   *
   * @return \Drupal\rdf_etl\RdfEtlStepList
   *   The sequence definition.
   */
  public function getStepList(): RdfEtlStepList;

  /**
   * Returns an individual step definition.
   *
   * @param int $sequence
   *   The offset in the list.
   *
   * @return string
   *   The step definition.
   */
  public function getStepPluginId(int $sequence): string;

  /**
   * Sets step-iterator to the given position.
   *
   * @param int $sequence
   *   The position in the flow.
   */
  public function setActiveStep(int $sequence): void;

}
