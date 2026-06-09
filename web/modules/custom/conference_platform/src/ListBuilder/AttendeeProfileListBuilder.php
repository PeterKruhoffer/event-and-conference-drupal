<?php

namespace Drupal\conference_platform\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Builds the attendee profile admin listing.
 */
class AttendeeProfileListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['name'] = $this->t('Name');
    $header['email'] = $this->t('Email');
    $header['organization'] = $this->t('Organization');
    $header['owner'] = $this->t('Owner');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['name'] = $entity->label();
    $row['email'] = $entity->get('email')->value ?: '';
    $row['organization'] = $entity->get('organization')->value ?: '';
    $row['owner'] = $entity->get('uid')->entity?->label() ?: '';

    return $row + parent::buildRow($entity);
  }

}
