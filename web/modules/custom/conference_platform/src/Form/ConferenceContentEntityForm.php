<?php

namespace Drupal\conference_platform\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides shared save behavior for conference content entity forms.
 */
class ConferenceContentEntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('Saved %label.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirect("entity.{$this->entity->getEntityTypeId()}.collection");

    return $result;
  }

}
