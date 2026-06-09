<?php

namespace Drupal\conference_platform\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Builds the waitlist entry admin listing.
 */
class WaitlistEntryListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['event'] = $this->t('Event');
    $header['profile'] = $this->t('Attendee');
    $header['position'] = $this->t('Position');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['event'] = $entity->get('event')->entity?->label() ?: '';
    $row['profile'] = $entity->get('profile')->entity?->label() ?: '';
    $row['position'] = $entity->get('position')->value ?: '';
    $row['status'] = ucfirst((string) $entity->get('waitlist_status')->value);

    return $row + parent::buildRow($entity);
  }

}
