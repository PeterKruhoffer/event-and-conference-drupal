<?php

namespace Drupal\conference_platform\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Builds the event registration admin listing.
 */
class EventRegistrationListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['event'] = $this->t('Event');
    $header['profile'] = $this->t('Attendee');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['event'] = $entity->get('event')->entity?->label() ?: '';
    $row['profile'] = $entity->get('profile')->entity?->label() ?: '';
    $row['status'] = ucfirst((string) $entity->get('registration_status')->value);
    $row['created'] = \Drupal::service('date.formatter')->format((int) $entity->get('created')->value, 'short');

    return $row + parent::buildRow($entity);
  }

}
