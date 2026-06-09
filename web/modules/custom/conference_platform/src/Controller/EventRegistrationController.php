<?php

namespace Drupal\conference_platform\Controller;

use Drupal\conference_platform\Entity\EventRegistration;
use Drupal\conference_platform\Entity\WaitlistEntry;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides frontend-facing event registration endpoints.
 */
class EventRegistrationController extends ControllerBase {

  /**
   * Constructs an EventRegistrationController object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * Returns current registration availability for an event.
   */
  public function availability(NodeInterface $event): JsonResponse {
    if (!$this->isEventNode($event)) {
      return $this->errorResponse('Event not found.', 404);
    }

    return new JsonResponse($this->buildAvailability($event));
  }

  /**
   * Creates or returns a registration for an event.
   */
  public function register(NodeInterface $event, Request $request): JsonResponse {
    if (!$this->isEventNode($event)) {
      return $this->errorResponse('Event not found.', 404);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->errorResponse('Submit a valid JSON request body.', 400);
    }

    if (!$this->signupIsOpen($event)) {
      return $this->errorResponse('Registration is closed for this event.', 400, [
        'availability' => $this->buildAvailability($event),
      ]);
    }

    $name = trim((string) ($payload['name'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $organization = trim((string) ($payload['organization'] ?? ''));
    $dietary_requirements = trim((string) ($payload['dietaryRequirements'] ?? ''));
    $notes = trim((string) ($payload['notes'] ?? ''));

    $errors = [];
    if ($name === '') {
      $errors['name'] = 'Enter the attendee name.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = 'Enter a valid email address.';
    }

    if ($errors) {
      return $this->errorResponse('Check the highlighted fields and try again.', 422, [
        'errors' => $errors,
      ]);
    }

    $profile = $this->loadOrCreateProfile([
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'organization' => $organization,
      'dietary_requirements' => $dietary_requirements,
    ]);

    $existing_registration = $this->loadExistingRegistration((int) $event->id(), (int) $profile->id());
    if ($existing_registration instanceof EventRegistration) {
      return $this->registrationResponse($existing_registration, $event, 200, TRUE);
    }

    $registration = $this->entityTypeManager
      ->getStorage('conference_event_registration')
      ->create([
        'event' => $event->id(),
        'profile' => $profile->id(),
        'registration_status' => EventRegistration::STATUS_ACCEPTED,
        'notes' => $notes,
        'uid' => $this->currentUser->id(),
      ]);
    $registration->save();

    return $this->registrationResponse($registration, $event, 201, FALSE);
  }

  /**
   * Builds a normalized availability payload.
   *
   * @return array<string, mixed>
   *   Event availability data.
   */
  protected function buildAvailability(NodeInterface $event): array {
    $event_id = (int) $event->id();
    $capacity = $this->getCapacity($event);
    $accepted_count = EventRegistration::countAcceptedRegistrations($event_id);
    $waitlist_count = $this->countWaitlistEntries($event_id);
    $open_seats = $capacity === NULL ? NULL : max($capacity - $accepted_count, 0);
    $signup_deadline = $this->getSignupDeadline($event);
    $signup_open = $this->signupIsOpen($event);

    if (!$signup_open) {
      $state = 'closed';
    }
    elseif ($capacity === NULL || $open_seats > 0) {
      $state = 'available';
    }
    else {
      $state = 'waitlist';
    }

    return [
      'eventId' => $event_id,
      'title' => $event->label(),
      'capacity' => $capacity,
      'acceptedCount' => $accepted_count,
      'waitlistCount' => $waitlist_count,
      'openSeats' => $open_seats,
      'signupDeadline' => $signup_deadline,
      'signupOpen' => $signup_open,
      'state' => $state,
      'nextWaitlistPosition' => $state === 'waitlist' ? WaitlistEntry::nextPosition($event_id) : NULL,
    ];
  }

  /**
   * Returns a registration response payload.
   */
  protected function registrationResponse(EventRegistration $registration, NodeInterface $event, int $status, bool $existing): JsonResponse {
    $payload = [
      'registrationId' => (int) $registration->id(),
      'profileId' => $registration->getProfileId(),
      'eventId' => (int) $event->id(),
      'status' => $registration->getRegistrationStatus(),
      'existing' => $existing,
      'waitlistPosition' => NULL,
      'availability' => $this->buildAvailability($event),
    ];

    if ($registration->getRegistrationStatus() === EventRegistration::STATUS_WAITLISTED) {
      $waitlist_entry = $this->loadWaitlistEntry((int) $event->id(), (int) $registration->getProfileId());
      if ($waitlist_entry instanceof ContentEntityInterface && $waitlist_entry->hasField('position')) {
        $payload['waitlistPosition'] = (int) $waitlist_entry->get('position')->value;
      }
    }

    return new JsonResponse($payload, $status);
  }

  /**
   * Loads an existing profile by email or creates one.
   *
   * @param array<string, string> $values
   *   Submitted attendee values.
   */
  protected function loadOrCreateProfile(array $values): EntityInterface {
    $profile_storage = $this->entityTypeManager->getStorage('conference_attendee_profile');
    $existing = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('email', $values['email'])
      ->range(0, 1)
      ->execute();

    if ($existing) {
      return $profile_storage->load(reset($existing));
    }

    $profile = $profile_storage->create([
      'name' => $values['name'],
      'email' => $values['email'],
      'phone' => $values['phone'],
      'organization' => $values['organization'],
      'dietary_requirements' => $values['dietary_requirements'],
      'uid' => $this->currentUser->id(),
    ]);
    $profile->save();

    return $profile;
  }

  /**
   * Loads an active registration for an event/profile pair.
   */
  protected function loadExistingRegistration(int $event_id, int $profile_id): ?EventRegistration {
    $registration_storage = $this->entityTypeManager->getStorage('conference_event_registration');
    $existing = $registration_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('profile', $profile_id)
      ->condition('registration_status', EventRegistration::STATUS_CANCELLED, '<>')
      ->range(0, 1)
      ->execute();

    if (!$existing) {
      return NULL;
    }

    $registration = $registration_storage->load(reset($existing));
    return $registration instanceof EventRegistration ? $registration : NULL;
  }

  /**
   * Loads an active waitlist entry for an event/profile pair.
   */
  protected function loadWaitlistEntry(int $event_id, int $profile_id): ?EntityInterface {
    $waitlist_storage = $this->entityTypeManager->getStorage('conference_waitlist_entry');
    $existing = $waitlist_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('profile', $profile_id)
      ->condition('waitlist_status', WaitlistEntry::STATUS_CANCELLED, '<>')
      ->range(0, 1)
      ->execute();

    return $existing ? $waitlist_storage->load(reset($existing)) : NULL;
  }

  /**
   * Counts active waitlist entries for an event.
   */
  protected function countWaitlistEntries(int $event_id): int {
    return (int) $this->entityTypeManager
      ->getStorage('conference_waitlist_entry')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('waitlist_status', WaitlistEntry::STATUS_CANCELLED, '<>')
      ->count()
      ->execute();
  }

  /**
   * Returns an event capacity, or NULL for unlimited/unknown capacity.
   */
  protected function getCapacity(NodeInterface $event): ?int {
    if (!$event->hasField('field_capacity') || $event->get('field_capacity')->isEmpty()) {
      return NULL;
    }

    $capacity = (int) $event->get('field_capacity')->value;
    return $capacity >= 0 ? $capacity : NULL;
  }

  /**
   * Returns the event signup deadline value.
   */
  protected function getSignupDeadline(NodeInterface $event): ?string {
    if (!$event->hasField('field_signup_deadline') || $event->get('field_signup_deadline')->isEmpty()) {
      return NULL;
    }

    return (string) $event->get('field_signup_deadline')->value;
  }

  /**
   * Checks whether frontend registration is still open.
   */
  protected function signupIsOpen(NodeInterface $event): bool {
    $deadline = $this->getSignupDeadline($event);
    if ($deadline === NULL) {
      return TRUE;
    }

    $deadline_timestamp = strtotime($deadline);
    return $deadline_timestamp === FALSE || $deadline_timestamp > time();
  }

  /**
   * Checks that the node is an event.
   */
  protected function isEventNode(NodeInterface $event): bool {
    return $event->bundle() === 'event';
  }

  /**
   * Creates an error response.
   *
   * @param array<string, mixed> $extra
   *   Additional payload values.
   */
  protected function errorResponse(string $message, int $status, array $extra = []): JsonResponse {
    return new JsonResponse(['message' => $message] + $extra, $status);
  }

}
