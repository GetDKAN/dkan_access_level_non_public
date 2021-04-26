<?php

namespace Drupal\Tests\dkan_access_level_non_public\Unit;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Symfony\Component\EventDispatcher\Event;

class ContainerAwareEventDispatcherMock extends ContainerAwareEventDispatcher
{
  protected function getCustomListeners() {
    return [];
  }

  public function dispatch($event_name, Event $event = NULL)
  {
    foreach ($this->getCustomListeners() as $eventName => $callable) {
      $this->addListener($eventName, $callable);
    }
    return parent::dispatch($event_name, $event);
  }
}
