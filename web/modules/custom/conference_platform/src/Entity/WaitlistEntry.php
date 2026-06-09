<?php

namespace Drupal\conference_platform\Entity;

use Drupal\conference_platform\ConferenceEntityAccessControlHandler;
use Drupal\conference_platform\Form\ConferenceContentEntityForm;
use Drupal\conference_platform\ListBuilder\WaitlistEntryListBuilder;
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
 * Defines the waitlist entry entity.
 */
#[ContentEntityType(
  id: 'conference_waitlist_entry',
  label: new TranslatableMarkup('Waitlist entry'),
  label_collection: new TranslatableMarkup('Waitlist entries'),
  label_singular: new TranslatableMarkup('waitlist entry'),
  label_plural: new TranslatableMarkup('waitlist entries'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => ConferenceEntityAccessControlHandler::class,
    'list_builder' => WaitlistEntryListBuilder::class,
    'form' => [
      'default' => ConferenceContentEntityForm::class,
      'add' => ConferenceContentEntityForm::class,
      'edit' => ConferenceContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/content/conference/waitlist-entries/add',
    'collection' => '/admin/content/conference/waitlist-entries',
    'edit-form' => '/admin/content/conference/waitlist-entry/{conference_waitlist_entry}/edit',
    'delete-form' => '/admin/content/conference/waitlist-entry/{conference_waitlist_entry}/delete',
  ],
  admin_permission: 'administer conference platform',
  collection_permission: 'view conference waitlist entries',
  base_table: 'conference_waitlist_entry',
  label_count: [
    'singular' => '@count waitlist entry',
    'plural' => '@count waitlist entries',
  ],
)]
class WaitlistEntry extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const STATUS_WAITING = 'waiting';
  public const STATUS_INVITED = 'invited';
  public const STATUS_PROMOTED = 'promoted';
  public const STATUS_CANCELLED = 'cancelled';

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);
    $values += [
      'uid' => \Drupal::currentUser()->id(),
      'waitlist_status' => self::STATUS_WAITING,
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

    $event_id = $this->getEventId();
    if (!$this->get('position')->value && $event_id) {
      $this->set('position', self::nextPosition($event_id));
    }

    $profile_label = $this->getProfile()?->label() ?: $this->getProfileId() ?: 'Unknown attendee';
    $event_label = $this->getEvent()?->label() ?: $event_id ?: 'Unknown event';
    $this->set('label', "$profile_label - $event_label waitlist");
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
   * Calculates the next waitlist position for an event.
   */
  public static function nextPosition(int $event_id): int {
    $count = \Drupal::entityTypeManager()
      ->getStorage('conference_waitlist_entry')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('waitlist_status', self::STATUS_CANCELLED, '<>')
      ->count()
      ->execute();

    return ((int) $count) + 1;
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
        'weight' => -30,
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
        'weight' => -20,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['registration'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Registration'))
      ->setSetting('target_type', 'conference_event_registration')
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

    $fields['position'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Position'))
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['waitlist_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Waitlist status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_WAITING)
      ->setSetting('allowed_values', [
        self::STATUS_WAITING => 'Waiting',
        self::STATUS_INVITED => 'Invited',
        self::STATUS_PROMOTED => 'Promoted',
        self::STATUS_CANCELLED => 'Cancelled',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 10,
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
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
