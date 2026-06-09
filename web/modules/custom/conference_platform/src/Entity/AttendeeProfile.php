<?php

namespace Drupal\conference_platform\Entity;

use Drupal\conference_platform\ConferenceEntityAccessControlHandler;
use Drupal\conference_platform\Form\ConferenceContentEntityForm;
use Drupal\conference_platform\ListBuilder\AttendeeProfileListBuilder;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the attendee profile entity.
 */
#[ContentEntityType(
  id: 'conference_attendee_profile',
  label: new TranslatableMarkup('Attendee profile'),
  label_collection: new TranslatableMarkup('Attendee profiles'),
  label_singular: new TranslatableMarkup('attendee profile'),
  label_plural: new TranslatableMarkup('attendee profiles'),
  entity_keys: [
    'id' => 'id',
    'label' => 'name',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => ConferenceEntityAccessControlHandler::class,
    'list_builder' => AttendeeProfileListBuilder::class,
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
    'add-form' => '/admin/content/conference/attendee-profiles/add',
    'collection' => '/admin/content/conference/attendee-profiles',
    'edit-form' => '/admin/content/conference/attendee-profile/{conference_attendee_profile}/edit',
    'delete-form' => '/admin/content/conference/attendee-profile/{conference_attendee_profile}/delete',
  ],
  admin_permission: 'administer conference platform',
  collection_permission: 'view conference attendee profiles',
  base_table: 'conference_attendee_profile',
  label_count: [
    'singular' => '@count attendee profile',
    'plural' => '@count attendee profiles',
  ],
)]
class AttendeeProfile extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);
    $values += ['uid' => \Drupal::currentUser()->id()];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('The attendee full name.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Email'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Phone'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['organization'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Organization'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['dietary_requirements'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Dietary requirements'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 30,
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
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
