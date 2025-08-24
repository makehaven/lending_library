<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BatteryReturnController extends ControllerBase {

  protected $formBuilder;
  protected $entityTypeManager;

  public function __construct(FormBuilderInterface $form_builder, EntityTypeManagerInterface $entity_type_manager) {
    $this->formBuilder = $form_builder;
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('entity_type.manager')
    );
  }

  public function return($battery) {
    $storage = $this->entityTypeManager->getStorage('battery');
    $bat = $storage->load($battery);
    if (!$bat) {
      $this->messenger()->addError($this->t('Battery not found.'));
      return $this->redirect('<front>');
    }

    return $this->formBuilder->getForm('\Drupal\lending_library\Form\BatteryReturnConfirmForm', $bat);
  }
}
