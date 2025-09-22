<?php

namespace Drupal\lending_library\EventSubscriber;

use Drupal\Core\Entity\EntityEvents;

use Drupal\lending_library\Service\ToolStatusUpdater;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityEvents;
use Drupal\Core\Entity\Event\EntityInsertEvent;
use Drupal\Core\Entity\Event\EntityUpdateEvent;
use Drupal\Core\Entity\EntityInterface;

/**
 * Reacts to library transaction saves.
 */
class TransactionSubscriber implements EventSubscriberInterface {

  /**
   * The tool status updater service.
   *
   * @var \Drupal\lending_library\Service\ToolStatusUpdater
   */
  protected $updater;

  /**
   * Constructs a new TransactionSubscriber.
   *
   * @param \Drupal\lending_library\Service\ToolStatusUpdater $updater
   *   The tool status updater service.
   */
  public function __construct(ToolStatusUpdater $updater) {
    $this->updater = $updater;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEvents::INSERT => 'onInsert',
      EntityEvents::UPDATE => 'onUpdate',
    ];
  }

  /**
   * This method is called when a new entity is inserted.
   *
   * @param \Drupal\Core\Entity\Event\EntityInsertEvent $event
   *   The event.
   */
  public function onInsert(EntityInsertEvent $event): void {
    $this->handle($event->getEntity());
  }

  /**
   * This method is called when an entity is updated.
   *
   * @param \Drupal\Core\Entity\Event\EntityUpdateEvent $event
   *   The event.
   */
  public function onUpdate(EntityUpdateEvent $event): void {
    $this->handle($event->getEntity());
  }

  /**
   * Handles the entity event.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  private function handle(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'library_transaction') {
      return;
    }
    $this->updater->updateFromTransaction($entity);
  }
}
