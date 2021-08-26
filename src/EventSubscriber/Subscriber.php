<?php

namespace Drupal\dkan_access_level_non_public\EventSubscriber;

use Drupal\common\Events\Event;
use Drupal\datastore\SqlEndpoint\WebServiceApi as DatastoreSqlController;
use Drupal\metastore\Service as MetastoreService;
use RootedData\RootedJsonData;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber.
 */
class Subscriber implements EventSubscriberInterface {

  /**
   * List of schemas to protect.
   *
   * @var array
   */
  private $schemasToModify = [
    'dataset',
    'distribution',
  ];

  /**
   * Inherited.
   *
   * @inheritdoc
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[DatastoreSqlController::EVENT_RUN_QUERY][] = ['onDatastoreSqlRunQuery'];
    $events[MetastoreService::EVENT_DATA_GET][] = ['onMetastoreDataGet'];
    return $events;
  }

  public function onMetastoreDataGet(Event $event) {
    /* @var $data RootedJsonData */
    $data = $event->getData();

    $type = $this->guessType($data);

    if ($data && $this->requiresModification($type, $data)) {
      switch($type) {
        case 'dataset':
          $data = $this->protectDataset($data);
          break;
        case 'distribution':
          $data = $this->protectDistribution($data);
          break;
      }
    }

    $event->setData($data);
  }

  public function onDatastoreSqlRunQuery(Event $event) {
    $distributionUuid = $event->getData();
    $datasetJson = $this->getDistributionsDataset($distributionUuid);
    if ($datasetJson && $this->requiresModification('dataset', new RootedJsonData($datasetJson))) {
      $event->setException(new \Exception("Can't access the datastore"));
    }
  }

  private function guessType(RootedJsonData $data): string {
    $type = 'unknown';
    if (isset($data->{'$.distribution'})) {
      $type = 'dataset';
    }
    elseif (isset($data->{'$.downloadURL'}) || isset($data->{'$.data.downloadURL'})) {
      $type = 'distribution';
    }
    return $type;
  }

  /**
   * Protect dataset object.
   *
   * @param object $dataset
   *   The dataset object.
   *
   * @return object
   *   The protected dataset object
   */
  private function protectDataset(RootedJsonData $dataset): RootedJsonData {
    $json = "{$dataset}";

    $object = json_decode($json);

    $properties = ['distribution', '%Ref:distribution'];

    foreach ($properties as $property) {
      if (isset($object->{$property}) && is_array($object->{$property})) {
        foreach ($object->{$property} as $key => &$dist) {
          $object->{$property}[$key] = $this->protectDistribution((object) $dist);
        }
      }
    }

    return new RootedJsonData(json_encode($object));
  }

  /**
   * Protect distribution object.
   *
   * @param object $dist
   *   A distribution object.
   *
   * @return object
   *   A protected distribution object, with an explanation.
   */
  private function protectDistribution(object $dist): object {
    unset($dist);
    return (object) ['title' => "This is a non-public file. The explorer has been disabled."];
  }

  /**
   * Get a distribution's parent dataset.
   *
   * @param string $distributionUuid
   *   A distribution's uuid.
   *
   * @return string|bool
   *   A dataset (json string) or false
   */
  private function getDistributionsDataset(string $distributionUuid) {
    $datasets = \Drupal::service('database')->select('node__field_json_metadata', 'm')
      ->condition('m.field_json_metadata_value', '%accessLevel%', 'LIKE')
      ->condition('m.field_json_metadata_value', "%{$distributionUuid}%", 'LIKE')
      ->fields('m', ['field_json_metadata_value'])
      ->execute()
      ->fetchCol();

    return reset($datasets);
  }

  /**
   * Check if a resource needs to be protected.
   *
   * @param string $schema
   *   The schema id.
   * @param object $data
   *   A dataset or distribution.
   *
   * @return bool
   *   TRUE if the data requires modification, FALSE otherwise.
   */
  public function requiresModification(string $schema, object $data) {
    return in_array($schema, $this->schemasToModify)
      && !$this->alternateEndpoint()
      && $this->accessLevel($schema, $data) === 'non-public';
  }

  /**
   * Check if user requests one of the alternate dkan_alt_api endpoint.
   *
   * @return bool
   *   TRUE from alternate endpoints, FALSE otherwise.
   */
  private function alternateEndpoint() {
    $routeName = \Drupal::service('current_route_match')->getRouteName();
    return strpos($routeName, 'dkan_alt_api.') === 0;
  }

  /**
   * Check if a dataset or a distribution's parent has non-public access level.
   *
   * @param string $schema
   *   The schema id.
   * @param object $data
   *   Object, json or identifier string representing a dataset or distribution.
   *
   * @return bool
   *   TRUE if non-public, FALSE otherwise.
   */
  private function accessLevel(string $schema, RootedJsonData $data) : string {
    // For distributions, check their parent dataset's access level.
    if ('distribution' === $schema) {
      return $this->parentDatasetAccessLevel($data);
    }
    return $this->datasetAccessLevel($data);
  }

  /**
   * Returns a dataset's access level.
   *
   * @param object|string $data
   *   A dataset object or json string.
   *
   * @return string
   *   The access level of the dataset.
   */
  private function datasetAccessLevel(RootedJsonData $data) {
    return $data->{'$.accessLevel'};
  }

  /**
   * Get the access level of a distribution's parent dataset.
   *
   * @param object|string $dist
   *   Object, json or identifier string representing a distribution.
   *
   * @return string
   *   The parent dataset's access level.
   */
  private function parentDatasetAccessLevel(object $dist) {
    $identifier = $this->getIdentifier($dist);
    $parentDataset = $this->getDistributionsDataset($identifier);
    return $this->datasetAccessLevel($parentDataset);
  }

  /**
   * Get a distribution's identifier, from its object or json string.
   *
   * @param object|string $dist
   *   Object, json or identifier string representing a distribution.
   *
   * @return string
   *   The distribution's identifier.
   */
  private function getIdentifier(object $dist) : string {
    return $dist->identifier;
  }

}
