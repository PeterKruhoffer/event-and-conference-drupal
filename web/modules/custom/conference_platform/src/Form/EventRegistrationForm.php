<?php

namespace Drupal\conference_platform\Form;

use Drupal\conference_platform\Entity\EventRegistration;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the event registration form with capacity enforcement.
 */
class EventRegistrationForm extends ConferenceContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $entity = $this->buildEntity($form, $form_state);
    if (!$entity instanceof EventRegistration) {
      return;
    }

    $event_id = $entity->getEventId();
    $profile_id = $entity->getProfileId();
    if (!$event_id || !$profile_id) {
      return;
    }

    $duplicate_query = $this->entityTypeManager
      ->getStorage('conference_event_registration')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('profile', $profile_id)
      ->condition('registration_status', EventRegistration::STATUS_CANCELLED, '<>')
      ->range(0, 1);

    if (!$entity->isNew()) {
      $duplicate_query->condition('id', $entity->id(), '<>');
    }

    if ($duplicate_query->execute()) {
      $form_state->setErrorByName('profile', $this->t('This attendee already has an active registration or waitlist entry for the selected event.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->getEntity();
    if (!$entity instanceof EventRegistration) {
      return parent::save($form, $form_state);
    }

    if (!$entity->getRegistrationStatus()) {
      $entity->setRegistrationStatus(EventRegistration::STATUS_ACCEPTED);
    }

    $waitlisted = FALSE;
    $event_id = $entity->getEventId();
    $lock_name = $event_id ? "conference_platform:event_registration:$event_id" : NULL;

    if ($lock_name && !\Drupal::lock()->acquire($lock_name, 30.0)) {
      $this->messenger()->addError($this->t('Could not reserve a registration slot. Please try again.'));
      return NULL;
    }

    try {
      if (
        $entity->getRegistrationStatus() === EventRegistration::STATUS_ACCEPTED &&
        !$entity->eventHasOpenSeat()
      ) {
        $entity->setRegistrationStatus(EventRegistration::STATUS_WAITLISTED);
        $waitlisted = TRUE;
      }

      $result = $entity->save();

      if ($entity->getRegistrationStatus() === EventRegistration::STATUS_WAITLISTED) {
        $entity->ensureWaitlistEntry();
      }

      if ($waitlisted) {
        $this->messenger()->addWarning($this->t('%label was added to the waitlist because the event is at capacity.', [
          '%label' => $entity->label(),
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Saved %label.', [
          '%label' => $entity->label(),
        ]));
      }

      $form_state->setRedirect('entity.conference_event_registration.collection');

      return $result;
    }
    finally {
      if ($lock_name) {
        \Drupal::lock()->release($lock_name);
      }
    }
  }

}
