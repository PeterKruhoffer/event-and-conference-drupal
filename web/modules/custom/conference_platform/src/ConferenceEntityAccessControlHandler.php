<?php

namespace Drupal\conference_platform;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides permission-based access for conference entities.
 */
class ConferenceEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * Maps entity operations to module permissions.
   *
   * @var array<string, array<string, string>>
   */
  protected array $permissions = [
    'conference_attendee_profile' => [
      'view' => 'view conference attendee profiles',
      'create' => 'create conference attendee profiles',
      'update' => 'edit conference attendee profiles',
      'delete' => 'delete conference attendee profiles',
    ],
    'conference_event_registration' => [
      'view' => 'view conference event registrations',
      'create' => 'create conference event registrations',
      'update' => 'edit conference event registrations',
      'delete' => 'delete conference event registrations',
    ],
    'conference_waitlist_entry' => [
      'view' => 'view conference waitlist entries',
      'create' => 'create conference waitlist entries',
      'update' => 'edit conference waitlist entries',
      'delete' => 'delete conference waitlist entries',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer conference platform')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $permission = $this->permissions[$entity->getEntityTypeId()][$operation] ?? NULL;
    if ($permission) {
      return AccessResult::allowedIfHasPermission($account, $permission);
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    if ($account->hasPermission('administer conference platform')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $entity_type_id = $context['entity_type_id'] ?? '';
    $permission = $this->permissions[$entity_type_id]['create'] ?? NULL;
    if ($permission) {
      return AccessResult::allowedIfHasPermission($account, $permission);
    }

    return AccessResult::neutral();
  }

}
