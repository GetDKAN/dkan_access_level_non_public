<?php

namespace Drupal\Tests\dkan_access_level_non_public\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\datastore\SqlEndpoint\Service as DatastoreSqlService;
use Drupal\datastore\SqlEndpoint\WebServiceApi;
use Drupal\dkan_access_level_non_public\EventSubscriber\Subscriber;
use Drupal\metastore\Factory\Sae;
use Drupal\metastore\Service as MetastoreService;
use Drupal\Tests\metastore\Unit\ServiceTest;
use MockChain\Chain;
use MockChain\Options;
use MockChain\Sequence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AccessLevelNonPublicTest extends TestCase {

  public function testSqlEnpointRestrictions() {
    $container = $this->getContainerMockChain('hello')->getMock();

    \Drupal::setContainer($container);

    $controller = WebServiceApi::create($container);
    $response = $controller->runQueryGet();

    $this->assertEquals(
      "Can't access the datastore",
      json_decode($response->getContent())->message
    );
  }

  public function testSqlEnpointAccessTrhoughAltApi() {
    $chain = $this->getContainerMockChain('dkan_alt_api.');
    $chain->add(DatastoreSqlService::class, 'runQuery', [["blue" => "jeans"]]);
    $container = $chain->getMock();

    \Drupal::setContainer($container);

    $controller = WebServiceApi::create($container);
    $response = $controller->runQueryGet();

    $this->assertEquals(
      json_encode([["blue" => "jeans"]]),
      $response->getContent()
    );
  }

  public function testMetastoreGetAllDatasetModifications() {

    $distro1 = (object) ['downloadURL' => 'google.com'];

    $dataset1 = (object) [
      'identifier' => '1',
      'accessLevel' => 'public',
      'distribution' => [$distro1]
    ];

    $distro2 = (object) ['downloadURL' => 'google.com'];

    $dataset2 = (object) [
      'identifier' => '2',
      'accessLevel' => 'non-public',
      'distribution' => [$distro2]
    ];

    $datasets = [
      json_encode($dataset1),
      json_encode($dataset2)
    ];

    $services  = (new Options())
      ->add('event_dispatcher', ContainerAwareEventDispatcherMock::class)
      ->add('current_route_match', RouteMatchInterface::class);

    $listeners = [
      MetastoreService::EVENT_DATA_GET => [new Subscriber(), 'onMetastoreDataGet']
    ];

    $containerChain = ServiceTest::getCommonMockChain($this, $services);

    $containerChain
      ->add(Sae::class, 'getInstance', \Sae\Sae::class)
      ->add(\Sae\Sae::class, 'get', $datasets)
      ->add(ContainerAwareEventDispatcherMock::class, 'getCustomListeners', $listeners)
      ->add(RouteMatchInterface::class, 'getRouteName', "blah");

    $container = $containerChain->getMock();

    \Drupal::setContainer($container);

    $service = MetastoreService::create($container);
    $datasetsFromService = $service->getAll('dataset');

    $this->assertEquals(
      $dataset1->distribution,
      $datasetsFromService[0]->distribution
    );

    $this->assertNotEquals(
      $dataset2->distribution,
      $datasetsFromService[1]->distribution
    );
  }

  public function testMetastoreGetAllDistributiontModifications() {

    $distro1 = (object) [
      'identifier' => '3',
      'downloadURL' => 'google.com'
    ];

    $dataset1 = (object) [
      'identifier' => '1',
      'accessLevel' => 'public',
      'distribution' => [$distro1]
    ];

    $distro2 = (object) [
      'identifier' => '4',
      'downloadURL' => 'google.com'
    ];

    $dataset2 = (object) [
      'identifier' => '2',
      'accessLevel' => 'non-public',
      'distribution' => [$distro2]
    ];

    $distributions = [
      json_encode($distro1),
      json_encode($distro2)
    ];

    $services  = (new Options())
      ->add('event_dispatcher', ContainerAwareEventDispatcherMock::class)
      ->add('current_route_match', RouteMatchInterface::class)
      ->add('database', Connection::class);

    $listeners = [
      MetastoreService::EVENT_DATA_GET => [new Subscriber(), 'onMetastoreDataGet']
    ];

    $containerChain = ServiceTest::getCommonMockChain($this, $services);

    $datasetsToReturn = (new Sequence())
      ->add([json_encode($dataset1)])
      ->add([json_encode($dataset2)]);

    $containerChain
      ->add(Sae::class, 'getInstance', \Sae\Sae::class)
      ->add(\Sae\Sae::class, 'get', $distributions)
      ->add(ContainerAwareEventDispatcherMock::class, 'getCustomListeners', $listeners)
      ->add(RouteMatchInterface::class, 'getRouteName', "blah")
      ->add(Connection::class, 'select', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->addd('fields', Select::class)
      ->addd('execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetchCol', $datasetsToReturn);

    $container = $containerChain->getMock();

    \Drupal::setContainer($container);

    $service = MetastoreService::create($container);
    $distributionsFromService = $service->getAll('distribution');

    $this->assertEquals(
      $distro1,
     $distributionsFromService[0]
    );

    $this->assertNotEquals(
      $distro2,
      $distributionsFromService[1]
    );

  }

  public function testEventsSubscribed() {
    $this->assertIsArray(Subscriber::getSubscribedEvents());
  }

  private function getContainerMockChain($routeName) {
    $options = (new Options())
      ->add('dkan.datastore.sql_endpoint.service', DatastoreSqlService::class)
      ->add('database', Connection::class)
      ->add('request_stack', RequestStack::class)
      ->add('event_dispatcher', ContainerAwareEventDispatcherMock::class)
      ->add('current_route_match', RouteMatchInterface::class)
      ->index(0);

    $listeners = [
      WebServiceApi::EVENT_RUN_QUERY => [new Subscriber(), 'onDatastoreSqlRunQuery']
    ];

    return (new Chain($this))
      ->add(Container::class, 'get', $options)
      ->add(RequestStack::class, 'getCurrentRequest', Request::class)
      ->add(Request::class, 'get', "SELECT * FROM blah")
      ->add(DatastoreSqlService::class, 'getResourceUuid', 'blah')
      ->add(ContainerAwareEventDispatcherMock::class, 'getCustomListeners', $listeners)
      ->add(Connection::class, 'select', Select::class)
      ->add(Select::class, 'condition', Select::class)
      ->addd('fields', Select::class)
      ->addd('execute', StatementInterface::class)
      ->add(StatementInterface::class, 'fetchCol', ['{"accessLevel": "non-public"}'])
      ->add(RouteMatchInterface::class, 'getRouteName', $routeName);
  }
}
