<?php

namespace Drupal\conference_platform\Entity;

use Drupal\conference_platform\ConferenceEntityAccessControlHandler;
use Drupal\conference_platform\Form\EventRegistrationForm;
use Drupal\conference_platform\ListBuilder\EventRegistrationListBuilder;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the event registration entity.
 */
#[ContentEntityType(
  id: 'conference_event_registration',
  label: new TranslatableMarkup('Event registration'),
  label_collection: new TranslatableMarkup('Event registrations'),
  label_singular: new TranslatableMarkup('event registration'),
  label_plural: new TranslatableMarkup('event registrations'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => ConferenceEntityAccessControlHandler::class,
    'list_builder' => EventRegistrationListBuilder::class,
    'form' => [
      'default' => EventRegistrationForm::class,
      'add' => EventRegistrationForm::class,
      'edit' => EventRegistrationForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/content/conference/event-registrations/add',
    'collection' => '/admin/content/conference/event-registrations',
    'edit-form' => '/admin/content/conference/event-registration/{conference_event_registration}/edit',
    'delete-form' => '/admin/content/conference/event-registration/{conference_event_registration}/delete',
  ],
  admin_permission: 'administer conference platform',
  collection_permission: 'view conference event registrations',
  base_table: 'conference_event_registration',
  label_count: [
    'singular' => '@count event registration',
    'plural' => '@count event registrations',
  ],
)]
class EventRegistration extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const STATUS_ACCEPTED = 'accepted';
  public const STATUS_WAITLISTED = 'waitlisted';
  public const STATUS_CANCELLED = 'cancelled';

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);
    $values += [
      'uid' => \Drupal::currentUser()->id(),
      'registration_status' => self::STATUS_ACCEPTED,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    if (!$this->getOwnerId()) {
      $this->setOwnerId(\Drupal::currentUser()->id());
    }

    if (!$this->getRegistrationStatus()) {
      $this->setRegistrationStatus(self::STATUS_ACCEPTED);
    }

    if ($this->getRegistrationStatus() === self::STATUS_ACCEPTED && !$this->eventHasOpenSeat()) {
      $this->setRegistrationStatus(self::STATUS_WAITLISTED);
    }

    $profile_label = $this->getProfile()?->label() ?: $this->getProfileId() ?: 'Unknown attendee';
    $event_label = $this->getEvent()?->label() ?: $this->getEventId() ?: 'Unknown event';
    $this->set('label', "$profile_label - $event_label");
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    if ($this->getRegistrationStatus() === self::STATUS_WAITLISTED) {
      $this->ensureWaitlistEntry();
    }
  }

  /**
   * Returns the referenced event.
   */
  public function getEvent(): ?NodeInterface {
    $event = $this->get('event')->entity;
    return $event instanceof NodeInterface ? $event : NULL;
  }

  /**
   * Returns the referenced event ID.
   */
  public function getEventId(): ?int {
    $target_id = $this->get('event')->target_id;
    return $target_id ? (int) $target_id : NULL;
  }

  /**
   * Returns the referenced attendee profile.
   */
  public function getProfile(): ?EntityInterface {
    return $this->get('profile')->entity;
  }

  /**
   * Returns the referenced attendee profile ID.
   */
  public function getProfileId(): ?int {
    $target_id = $this->get('profile')->target_id;
    return $target_id ? (int) $target_id : NULL;
  }

  /**
   * Returns the registration status.
   */
  public function getRegistrationStatus(): ?string {
    return $this->get('registration_status')->value;
  }

  /**
   * Sets the registration status.
   */
  public function setRegistrationStatus(string $status): static {
    $this->set('registration_status', $status);
    return $this;
  }

  /**
   * Checks whether the referenced event has room for this registration.
   */
  public function eventHasOpenSeat(): bool {
    $event = $this->getEvent();
    if (!$event instanceof NodeInterface) {
      return TRUE;
    }

    if (!$event->hasField('field_capacity') || $event->get('field_capacity')->isEmpty()) {
      return TRUE;
    }

    $capacity = (int) $event->get('field_capacity')->value;
    if ($capacity < 0) {
      return TRUE;
    }

    return self::countAcceptedRegistrations((int) $event->id(), $this->id() ? (int) $this->id() : NULL) < $capacity;
  }

  /**
   * Counts accepted registrations for an event.
   */
  public static function countAcceptedRegistrations(int $event_id, ?int $exclude_registration_id = NULL): int {
    $query = \Drupal::entityTypeManager()
      ->getStorage('conference_event_registration')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('registration_status', self::STATUS_ACCEPTED);

    if ($exclude_registration_id) {
      $query->condition('id', $exclude_registration_id, '<>');
    }

    return (int) $query->count()->execute();
  }

  /**
   * Creates or returns the waitlist entry for this registration.
   */
  public function ensureWaitlistEntry(): ?EntityInterface {
    $event_id = $this->getEventId();
    $profile_id = $this->getProfileId();
    if (!$event_id || !$profile_id || !$this->id()) {
      return NULL;
    }

    $waitlist_storage = \Drupal::entityTypeManager()->getStorage('conference_waitlist_entry');
    $existing = $waitlist_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('profile', $profile_id)
      ->range(0, 1)
      ->execute();

    if ($existing) {
      return $waitlist_storage->load(reset($existing));
    }

    $entry = $waitlist_storage->create([
      'event' => $event_id,
      'profile' => $profile_id,
      'registration' => $this->id(),
      'position' => WaitlistEntry::nextPosition($event_id),
      'waitlist_status' => WaitlistEntry::STATUS_WAITING,
      'uid' => $this->getOwnerId(),
    ]);
    $entry->save();

    return $entry;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setReadOnly(TRUE)
      ->setSetting('max_length', 255);

    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => ['event' => 'event'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -20,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['profile'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Attendee profile'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'conference_attendee_profile')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -10,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['registration_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Registration status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_ACCEPTED)
      ->setSetting('allowed_values', [
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_WAITLISTED => 'Waitlisted',
        self::STATUS_CANCELLED => 'Cancelled',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 20,
        'settings' => ['rows' => 3],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 90,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 90,
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
