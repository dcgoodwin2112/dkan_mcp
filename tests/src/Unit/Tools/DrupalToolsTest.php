<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\dkan_mcp\Tools\DrupalTools;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class DrupalToolsTest extends TestCase {

  protected function createTools(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?EntityFieldManagerInterface $entityFieldManager = NULL,
    ?EntityTypeBundleInfoInterface $bundleInfo = NULL,
    ?ModuleHandlerInterface $moduleHandler = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?RouteProviderInterface $routeProvider = NULL,
    ?ContainerInterface $container = NULL,
  ): DrupalTools {
    return new DrupalTools(
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $entityFieldManager ?? $this->createMock(EntityFieldManagerInterface::class),
      $bundleInfo ?? $this->createMock(EntityTypeBundleInfoInterface::class),
      $moduleHandler ?? $this->createMock(ModuleHandlerInterface::class),
      $configFactory ?? $this->createMock(ConfigFactoryInterface::class),
      $routeProvider ?? $this->createMock(RouteProviderInterface::class),
      $container ?? $this->createMock(ContainerInterface::class),
    );
  }

  protected function createEntityType(string $id, string $label, string $class, string $group, array $keys, string $storageClass, ?string $bundleEntityType = NULL): EntityTypeInterface {
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->method('getLabel')->willReturn($label);
    $entityType->method('getOriginalClass')->willReturn($class);
    $entityType->method('getGroup')->willReturn($group);
    $entityType->method('getKeys')->willReturn($keys);
    $entityType->method('getStorageClass')->willReturn($storageClass);
    $entityType->method('getBundleEntityType')->willReturn($bundleEntityType);
    return $entityType;
  }

  protected function createFieldDefinition(string $name, string $type, string $label, bool $required, int $cardinality, string $description, bool $isBase): FieldDefinitionInterface {
    $storageDef = $this->createMock(FieldStorageDefinitionInterface::class);
    $storageDef->method('getLabel')->willReturn($label);
    $storageDef->method('getCardinality')->willReturn($cardinality);
    $storageDef->method('getDescription')->willReturn($description);

    if ($isBase) {
      $fieldDef = $this->createMock(BaseFieldDefinition::class);
    }
    else {
      $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    }
    $fieldDef->method('getType')->willReturn($type);
    $fieldDef->method('isRequired')->willReturn($required);
    $fieldDef->method('getFieldStorageDefinition')->willReturn($storageDef);

    return $fieldDef;
  }

  // --- listEntityTypes tests ---

  public function testListEntityTypesReturnsTypesWithBundles(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);

    $nodeType = $this->createEntityType('node', 'Content', 'Drupal\node\Entity\Node', 'content', ['id' => 'nid', 'bundle' => 'type', 'label' => 'title', 'uuid' => 'uuid'], 'Drupal\node\NodeStorage', 'node_type');
    $userType = $this->createEntityType('user', 'User', 'Drupal\user\Entity\User', 'content', ['id' => 'uid', 'label' => 'name', 'uuid' => 'uuid'], 'Drupal\user\UserStorage');

    $entityTypeManager->method('getDefinitions')->willReturn([
      'node' => $nodeType,
      'user' => $userType,
    ]);

    $bundleInfo->method('getBundleInfo')->willReturnMap([
      ['node', ['article' => ['label' => 'Article'], 'page' => ['label' => 'Basic page']]],
      ['user', ['user' => ['label' => 'User']]],
    ]);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager, bundleInfo: $bundleInfo);
    $result = $tools->listEntityTypes();

    $this->assertEquals(2, $result['total']);
    $this->assertEquals('node', $result['entity_types'][0]['id']);
    $this->assertEquals('Content', $result['entity_types'][0]['label']);
    $this->assertCount(2, $result['entity_types'][0]['bundles']);
    $this->assertEquals('article', $result['entity_types'][0]['bundles'][0]['id']);
  }

  public function testListEntityTypesFiltersByGroup(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);

    $nodeType = $this->createEntityType('node', 'Content', 'Drupal\node\Entity\Node', 'content', [], 'Drupal\node\NodeStorage');
    $viewType = $this->createEntityType('view', 'View', 'Drupal\views\Entity\View', 'configuration', [], 'Drupal\views\ViewStorage');

    $entityTypeManager->method('getDefinitions')->willReturn([
      'node' => $nodeType,
      'view' => $viewType,
    ]);
    $bundleInfo->method('getBundleInfo')->willReturn([]);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager, bundleInfo: $bundleInfo);
    $result = $tools->listEntityTypes('content');

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('node', $result['entity_types'][0]['id']);
  }

  public function testListEntityTypesHandlesEmpty(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinitions')->willReturn([]);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager);
    $result = $tools->listEntityTypes();

    $this->assertEquals(0, $result['total']);
    $this->assertEmpty($result['entity_types']);
  }

  // --- getEntityFields tests ---

  public function testGetEntityFieldsReturnsFieldsForBundle(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $entityTypeManager->method('hasDefinition')->with('node')->willReturn(TRUE);
    $nodeDef = $this->createEntityType('node', 'Content', 'Drupal\node\Entity\Node', 'content', [], 'Drupal\node\NodeStorage', 'node_type');
    $entityTypeManager->method('getDefinition')->with('node')->willReturn($nodeDef);

    $titleField = $this->createFieldDefinition('title', 'string', 'Title', TRUE, 1, '', TRUE);
    $bodyField = $this->createFieldDefinition('body', 'text_with_summary', 'Body', FALSE, 1, 'Main body text', FALSE);

    $entityFieldManager->method('getFieldDefinitions')->with('node', 'article')->willReturn([
      'title' => $titleField,
      'body' => $bodyField,
    ]);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager, entityFieldManager: $entityFieldManager);
    $result = $tools->getEntityFields('node', 'article');

    $this->assertEquals(2, $result['total']);
    $this->assertEquals('title', $result['fields'][0]['name']);
    $this->assertEquals('string', $result['fields'][0]['type']);
    $this->assertTrue($result['fields'][0]['required']);
    $this->assertTrue($result['fields'][0]['is_base_field']);
    $this->assertEquals('body', $result['fields'][1]['name']);
    $this->assertFalse($result['fields'][1]['is_base_field']);
  }

  public function testGetEntityFieldsAutoResolvesNonBundleable(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $entityTypeManager->method('hasDefinition')->with('user')->willReturn(TRUE);
    $userDef = $this->createEntityType('user', 'User', 'Drupal\user\Entity\User', 'content', [], 'Drupal\user\UserStorage');
    $entityTypeManager->method('getDefinition')->with('user')->willReturn($userDef);

    $nameField = $this->createFieldDefinition('name', 'string', 'Name', TRUE, 1, '', TRUE);
    $entityFieldManager->method('getFieldDefinitions')->with('user', 'user')->willReturn([
      'name' => $nameField,
    ]);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager, entityFieldManager: $entityFieldManager);
    $result = $tools->getEntityFields('user');

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('name', $result['fields'][0]['name']);
  }

  public function testGetEntityFieldsReturnsBaseFieldsWhenNoBundleForBundleable(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $entityTypeManager->method('hasDefinition')->with('node')->willReturn(TRUE);
    $nodeDef = $this->createEntityType('node', 'Content', 'Drupal\node\Entity\Node', 'content', [], 'Drupal\node\NodeStorage', 'node_type');
    $entityTypeManager->method('getDefinition')->with('node')->willReturn($nodeDef);

    $nidField = $this->createFieldDefinition('nid', 'integer', 'ID', FALSE, 1, '', TRUE);
    $entityFieldManager->method('getBaseFieldDefinitions')->with('node')->willReturn([
      'nid' => $nidField,
    ]);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager, entityFieldManager: $entityFieldManager);
    $result = $tools->getEntityFields('node');

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('nid', $result['fields'][0]['name']);
  }

  public function testGetEntityFieldsErrorsOnInvalidType(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('nonexistent')->willReturn(FALSE);

    $tools = $this->createTools(entityTypeManager: $entityTypeManager);
    $result = $tools->getEntityFields('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found', $result['error']);
  }

  // --- listModules tests ---

  public function testListModulesReturnsMetadata(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $ext = $this->createMock(Extension::class);
    $ext->method('getPath')->willReturn('web/modules/contrib/dkan');
    $ext->info = [
      'name' => 'DKAN',
      'version' => '2.22',
      'package' => 'DKAN',
      'dependencies' => ['drupal:node'],
    ];

    $moduleHandler->method('getModuleList')->willReturn(['dkan' => $ext]);

    $tools = $this->createTools(moduleHandler: $moduleHandler);
    $result = $tools->listModules();

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('dkan', $result['modules'][0]['name']);
    $this->assertEquals('DKAN', $result['modules'][0]['human_name']);
    $this->assertEquals('2.22', $result['modules'][0]['version']);
    $this->assertEquals(['drupal:node'], $result['modules'][0]['dependencies']);
  }

  public function testListModulesFiltersByNameContains(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $dkanExt = $this->createMock(Extension::class);
    $dkanExt->method('getPath')->willReturn('web/modules/contrib/dkan');
    $dkanExt->info = ['name' => 'DKAN'];

    $nodeExt = $this->createMock(Extension::class);
    $nodeExt->method('getPath')->willReturn('core/modules/node');
    $nodeExt->info = ['name' => 'Node'];

    $moduleHandler->method('getModuleList')->willReturn([
      'dkan' => $dkanExt,
      'node' => $nodeExt,
    ]);

    $tools = $this->createTools(moduleHandler: $moduleHandler);
    $result = $tools->listModules('dkan');

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('dkan', $result['modules'][0]['name']);
  }

  public function testListModulesHandlesEmpty(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('getModuleList')->willReturn([]);

    $tools = $this->createTools(moduleHandler: $moduleHandler);
    $result = $tools->listModules();

    $this->assertEquals(0, $result['total']);
    $this->assertEmpty($result['modules']);
  }

  // --- getConfig tests ---

  public function testGetConfigReturnsByName(): void {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn(['name' => 'My Site', 'slogan' => 'A test site']);
    $configFactory->method('get')->with('system.site')->willReturn($config);

    $tools = $this->createTools(configFactory: $configFactory);
    $result = $tools->getConfig('system.site');

    $this->assertEquals('system.site', $result['config_name']);
    $this->assertEquals('My Site', $result['data']['name']);
    $this->assertEquals('A test site', $result['data']['slogan']);
  }

  public function testGetConfigListsByPrefix(): void {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('listAll')->with('dkan.')->willReturn([
      'dkan.settings',
      'dkan.metastore.settings',
    ]);

    $tools = $this->createTools(configFactory: $configFactory);
    $result = $tools->getConfig(NULL, 'dkan.');

    $this->assertEquals(2, $result['total']);
    $this->assertContains('dkan.settings', $result['config_names']);
  }

  public function testGetConfigErrorsWhenNeitherProvided(): void {
    $tools = $this->createTools();
    $result = $tools->getConfig();

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Provide either', $result['error']);
  }

  public function testGetConfigErrorsOnEmptyConfig(): void {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn([]);
    $configFactory->method('get')->with('nonexistent.config')->willReturn($config);

    $tools = $this->createTools(configFactory: $configFactory);
    $result = $tools->getConfig('nonexistent.config');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found or empty', $result['error']);
  }

  // --- listPlugins tests ---

  public function testListPluginsReturnsDefinitions(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with('plugin.manager.block')->willReturn(TRUE);

    $manager = new class {

      public function getDefinitions(): array {
        return [
          'system_main_block' => [
            'id' => 'system_main_block',
            'label' => 'Main page content',
            'class' => 'Drupal\system\Plugin\Block\SystemMainBlock',
            'provider' => 'system',
            'category' => 'System',
          ],
        ];
      }

    };
    $container->method('get')->with('plugin.manager.block')->willReturn($manager);

    $tools = $this->createTools(container: $container);
    $result = $tools->listPlugins('block');

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('system_main_block', $result['plugins'][0]['id']);
    $this->assertEquals('Main page content', $result['plugins'][0]['label']);
    $this->assertEquals('system', $result['plugins'][0]['provider']);
  }

  public function testListPluginsErrorsOnUnknownType(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with('plugin.manager.nonexistent')->willReturn(FALSE);

    $tools = $this->createTools(container: $container);
    $result = $tools->listPlugins('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Plugin manager not found', $result['error']);
  }

  public function testListPluginsHandlesMissingKeys(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->with('plugin.manager.minimal')->willReturn(TRUE);

    $manager = new class {

      public function getDefinitions(): array {
        return [
          'minimal_plugin' => ['id' => 'minimal_plugin'],
        ];
      }

    };
    $container->method('get')->with('plugin.manager.minimal')->willReturn($manager);

    $tools = $this->createTools(container: $container);
    $result = $tools->listPlugins('minimal');

    $this->assertEquals(1, $result['total']);
    $this->assertEquals('minimal_plugin', $result['plugins'][0]['id']);
    $this->assertArrayNotHasKey('label', $result['plugins'][0]);
    $this->assertArrayNotHasKey('class', $result['plugins'][0]);
  }

  // --- getRouteInfo tests ---

  public function testGetRouteInfoByName(): void {
    $routeProvider = $this->createMock(RouteProviderInterface::class);

    $route = new Route('/api/1/metastore/schemas/{schema_id}/items/{identifier}');
    $route->setDefaults(['_controller' => 'Drupal\metastore\Controller\MetastoreController::get']);
    $route->setRequirements(['_permission' => 'access content']);
    $route->setMethods(['GET']);

    $routeProvider->method('getRouteByName')->with('dkan.metastore.get')->willReturn($route);

    $tools = $this->createTools(routeProvider: $routeProvider);
    $result = $tools->getRouteInfo('dkan.metastore.get');

    $this->assertCount(1, $result['routes']);
    $this->assertEquals('dkan.metastore.get', $result['routes'][0]['name']);
    $this->assertEquals('/api/1/metastore/schemas/{schema_id}/items/{identifier}', $result['routes'][0]['path']);
    $this->assertEquals(['GET'], $result['routes'][0]['methods']);
    $this->assertEquals('_controller', $result['routes'][0]['controller']['type']);
    $this->assertEquals('access content', $result['routes'][0]['requirements']['_permission']);
  }

  public function testGetRouteInfoByPath(): void {
    $routeProvider = $this->createMock(RouteProviderInterface::class);

    $collection = new RouteCollection();
    $route1 = new Route('/api/1/datastore/query');
    $route1->setDefaults(['_controller' => 'Drupal\datastore\Controller\QueryController::query']);
    $collection->add('datastore.query', $route1);

    $route2 = new Route('/api/1/datastore/query/{id}');
    $route2->setDefaults(['_controller' => 'Drupal\datastore\Controller\QueryController::queryResource']);
    $collection->add('datastore.query.resource', $route2);

    $routeProvider->method('getRoutesByPattern')->with('/api/1/datastore')->willReturn($collection);

    $tools = $this->createTools(routeProvider: $routeProvider);
    $result = $tools->getRouteInfo(NULL, '/api/1/datastore');

    $this->assertEquals(2, $result['total']);
    $this->assertEquals('datastore.query', $result['routes'][0]['name']);
    $this->assertEquals('datastore.query.resource', $result['routes'][1]['name']);
  }

  public function testGetRouteInfoErrorsWhenNeitherProvided(): void {
    $tools = $this->createTools();
    $result = $tools->getRouteInfo();

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Provide either', $result['error']);
  }

  public function testGetRouteInfoErrorsOnUnknownRoute(): void {
    $routeProvider = $this->createMock(RouteProviderInterface::class);
    $routeProvider->method('getRouteByName')->willThrowException(new \Exception('Route not found'));

    $tools = $this->createTools(routeProvider: $routeProvider);
    $result = $tools->getRouteInfo('nonexistent.route');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Route not found', $result['error']);
  }

  public function testGetRouteInfoIncludesAdminRoute(): void {
    $routeProvider = $this->createMock(RouteProviderInterface::class);

    $route = new Route('/admin/config/system');
    $route->setDefaults(['_form' => 'Drupal\system\Form\SiteInformationForm']);
    $route->setRequirements(['_permission' => 'administer site configuration']);
    $route->setOption('_admin_route', TRUE);

    $routeProvider->method('getRouteByName')->with('system.site_information_settings')->willReturn($route);

    $tools = $this->createTools(routeProvider: $routeProvider);
    $result = $tools->getRouteInfo('system.site_information_settings');

    $this->assertTrue($result['routes'][0]['admin_route']);
    $this->assertEquals('_form', $result['routes'][0]['controller']['type']);
  }

}
