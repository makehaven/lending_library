<?php

namespace Drupal\lending_library\EventSubscriber;

use Drupal\Core\Entity\EntityEvents;
use Drupal\Core\Entity\Event\EntityInsertEvent;
use Drupal\Core\Entity\Event\EntityUpdateEvent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\lending_library\Service\ToolStatusUpdater;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TransactionSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly ToolStatusUpdater $updater) {}

  public static function getSubscribedEvents(): array {
    return [
      EntityEvents::INSERT => 'onInsert',
      EntityEvents::UPDATE => 'onUpdate',
    ];
  }

  public function onInsert(EntityInsertEvent $event): void {
    $this->handle($event->getEntity());
  }

  public function onUpdate(EntityUpdateEvent $event): void {
    $this->handle($event->getEntity());
  }

  private function handle(EntityInterface $entity): void {
    // Replace 'lending_library_transaction' with your exact entity type ID.
    if ($entity->getEntityTypeId() !== 'library_transaction') {
      return;
    }
    $this->updater->updateFromTransaction($entity);
  }
}
