<?php

namespace Drupal\tallinn\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\LinkItemInterface;
use Drupal\tallinn\Plugin\Field\FieldType\TallinnEntryItem;

/**
 * Plugin implementation of the 'tallinn_entry_default' widget.
 *
 * This field is a complex field that includes a text area field with a format,
 * a link field and an option select field. Each of them will be constructed,
 * validated and contain widget similar or the same as their individual plugins.
 *
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextareaWidget
 * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
 * @see \Drupal\link\Plugin\Field\FieldWidget\LinkWidget
 *
 * @FieldWidget(
 *   id = "tallinn_entry_default",
 *   label = @Translation("Tallinn entry widget"),
 *   field_types = {
 *     "tallinn_entry"
 *   }
 * )
 */
class TallinnEntryWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];

    $element = [
      '#type' => 'fieldset',
      '#title' => $this->fieldDefinition->getLabel(),
    ];

    $element['tallinn_description'] = [
      '#markup' => $this->fieldDefinition->getDescription(),
      '#weight' => 0,
    ];

    $element['explanation'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Explanations'),
      '#default_value' => $item->value,
      '#format' => $item->format,
      '#weight' => 1,
    ];

    $element['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Implementation status'),
      '#options' => $this->getStatusOptions(),
      '#default_value' => $item->status,
      '#weight' => 2,
    ];

    $element['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Related website'),
      '#default_value' => $item->uri,
      '#maxlength' => 2048,
      // Only external links, i.e. full links.
      '#link_type' => LinkItemInterface::LINK_EXTERNAL,
      '#weight' => 3,
    ];

    $element['#element_validate'][] = [get_called_class(), 'validateFormElement'];
    return $element;
  }

  /**
   * Form element validation handler for the complete form element.
   */
  public static function validateFormElement($element, FormStateInterface $form_state, $form) {
    $status = $element['status']['#value'];
    $explanation = $element['explanation']['value']['#value'];
    if ($status !== 'no_data' && empty($explanation)) {
      $form_state->setError($element, t(':title requires <em>Explanations</em> field filled if <em>Status</em> is not set to "No data".', [
        ':title' => $element['#title'],
      ]));
    }
  }

  /**
   * Returns a list of available options for the status select field.
   *
   * @return array
   *   A list of options.
   */
  protected function getStatusOptions() {
    return TallinnEntryItem::getStatusOptions();
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    $values = $values[0];
    foreach ($values as $delta => $delta_values) {
      if (!empty($values['explanation']['value'])) {
        $values += $values['explanation'];
      }
      unset($values['explanation']);

      // In case the uri field is not filled, unset the value because an empty
      // string will throw a primitive value issue.
      if (empty($values['uri'])) {
        unset($values['uri']);
      }
    }

    return $values;
  }

}
