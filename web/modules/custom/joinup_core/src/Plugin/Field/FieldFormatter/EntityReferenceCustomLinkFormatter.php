<?php

declare(strict_types = 1);

namespace Drupal\joinup_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\token\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'custom link' formatter.
 *
 * @FieldFormatter(
 *   id = "entity_reference_custom_link",
 *   label = @Translation("Custom link"),
 *   description = @Translation("Displays the label as a link to a customized path."),
 *   field_types = {
 *     "entity_reference",
 *   },
 * )
 */
class EntityReferenceCustomLinkFormatter extends EntityReferenceFormatterBase {

  /**
   * The token replacement service.
   *
   * @var \Drupal\token\Token
   */
  protected $token;

  /**
   * Constructs a FormatterBase object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\token\Token $token
   *   The token replacement service.
   */
  public function __construct(ContainerInterface $container, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, Token $token) {
    parent::__construct($container, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $container,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'path' => '[term:url]',
      'label' => '[term:label]',
      'query_parameters' => '',
      'limit' => -1,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements['path'] = [
      '#title' => $this->t('The path of the link'),
      '#description' => $this->t('The path supports tokens e.g. [term:id]. Internal paths should start with "internal:/"'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->getSetting('path'),
    ];

    $elements['label'] = [
      '#title' => $this->t('The link text to display'),
      '#description' => $this->t('The text supports tokens e.g. [term:label].'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->getSetting('label'),
    ];

    $elements['query_parameters'] = [
      '#title' => $this->t('Query parameters'),
      '#description' => $this->t('Add one query parameter per line split by "|". The text supports tokens e.g. [term:label]. The query parameter cannot contain the | symbol.'),
      '#type' => 'textarea',
      '#default_value' => $this->getSetting('query_parameters'),
    ];

    $elements['limit'] = [
      '#title' => $this->t('Maximum amount of links to show.'),
      '#description' => $this->t('Set this to -1 to display all links.'),
      '#attributes' => [
        ' type' => 'number',
      ],
      '#required' => TRUE,
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('limit'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    $summary[] = $this->getSetting('path') ?
      $this->t('Redirect path: %path.', [
        '%path' => $this->getSetting('path'),
      ]) : $this->t('No link');
    $summary[] = $this->t('Link label: %label.', [
      '%label' => $this->getSetting('label'),
    ]);
    $summary[] = $this->t('Query parameters: %parameters.', [
      '%parameters' => $this->getSetting('query_parameters'),
    ]);
    $summary[] = $this->t('Limit: %limit', [
      '%limit' => $this->getSetting('limit'),
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $path_pattern = $this->getSetting('path');
    $label_pattern = $this->getSetting('label');

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $items */
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      $query_parameters = $this->getQueryParameters($entity);

      $path = $this->token->replace($path_pattern, [$entity->getEntityTypeId() => $entity]);
      $label = $this->token->replace($label_pattern, [$entity->getEntityTypeId() => $entity]);

      $url = Url::fromUri($path, ['query' => $query_parameters]);
      $elements[$delta] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $url,
        '#options' => $url->getOptions(),
      ];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntitiesToView(EntityReferenceFieldItemListInterface $items, $langcode): array {
    $entities_to_view = parent::getEntitiesToView($items, $langcode);
    $limit = $this->getSetting('limit');
    if ($limit !== -1 && $limit < count($entities_to_view)) {
      // Due to the fact that delta is not yet implemented in SPARQL, in case
      // not all terms are shown, sort the terms alphabetically. This will keep
      // the results predictable and consistent across the website.
      usort($entities_to_view, function (EntityInterface $entity_a, EntityInterface $entity_b): int {
        return $entity_a->label() <=> $entity_b->label();
      });
      $entities_to_view = array_splice($entities_to_view, 0, $limit);
    }
    return $entities_to_view;
  }

  /**
   * Processes the query parameters and encodes the URL parameters.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity in the current delta.
   *
   * @return array
   *   An array of processed query parameters.
   */
  protected function getQueryParameters(EntityInterface $entity): array {
    $setting = $this->getSetting('query_parameters');
    $query_parameters = explode("\n", $setting);
    if (empty($query_parameters)) {
      return [];
    }

    $return = [];
    $query_parameters = array_filter(array_map('trim', explode("\n", str_replace("\r", "\n", $setting))));
    foreach ($query_parameters as $query_parameter) {
      [$name, $value] = explode('|', $query_parameter, 2);
      if (empty($name)) {
        continue;
      }
      $return[$name] = $this->token->replace($value, [$entity->getEntityTypeId() => $entity]);
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity): AccessResultInterface {
    return $entity->access('view label', NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    // This formatter is only available for taxonomy terms.
    return $field_definition->getFieldStorageDefinition()->getSetting('target_type') === 'taxonomy_term';
  }

}
