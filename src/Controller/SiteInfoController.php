<?php

namespace Drupal\thunder_performance_measurement\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sampler\Mapping;
use Drupal\sampler\SamplerPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The site info controller for performance testing.
 */
class SiteInfoController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The sampler plugin manager.
   *
   * @var \Drupal\sampler\SamplerPluginManager
   */
  protected $samplerPluginManager;

  /**
   * The sampler mapping service.
   *
   * @var \Drupal\sampler\Mapping
   */
  protected $samplerMapping;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new SiteInfoController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\sampler\SamplerPluginManager $sampler_plugin_manager
   *   The sampler plugin manager.
   * @param \Drupal\sampler\Mapping $sampler_mapping
   *   The sampler mapping service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(Connection $database, SamplerPluginManager $sampler_plugin_manager, Mapping $sampler_mapping, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->database = $database;
    $this->samplerPluginManager = $sampler_plugin_manager;
    $this->samplerMapping = $sampler_mapping;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('plugin.manager.sampler'),
      $container->get('sampler.mapping'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Get fields for the bundle.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   * @param array $bundle_info
   *   The bundle information with fields and instances.
   * @param int $threshold
   *   The threshold in percents for non-required fields.
   *
   * @return mixed
   *   Returns all required fields for bundle.
   */
  protected function getFieldWidgets($entity_type, $bundle, array $bundle_info, $threshold = 100) {
    $entity_form_display = EntityFormDisplay::load("{$entity_type}.{$bundle}.default");
    $form_display_widgets = $entity_form_display->getComponents();

    list('fields' => $bundle_fields, 'instances' => $bundle_instances) = $bundle_info;

    // Calculate percent of instances with filled field for bundle fields.
    foreach ($bundle_fields as &$bundle_info) {
      $bundle_info['percent_of_instances'] = $bundle_instances == 0 ? 100 : (array_sum($bundle_info['histogram']) / $bundle_instances * 100);
    }

    $fields = array_reduce(
      array_keys($form_display_widgets),
      function ($collection, $field_name) use ($form_display_widgets, $bundle_fields, $threshold) {
        $field_info = $form_display_widgets[$field_name];

        // Skip fields that are not displayed on form.
        if (!isset($field_info['region']) || $field_info['region'] !== 'content') {
          return $collection;
        }

        // Ensure that fields defined in form display exists in bundle fields.
        if (!isset($bundle_fields[$field_name])) {
          return $collection;
        }

        // Include required fields and fields over provided threshold.
        if ($bundle_fields[$field_name]['required'] || $bundle_fields[$field_name]['percent_of_instances'] >= $threshold) {
          $collection[$field_name] = $field_info;
        }

        return $collection;
      },
      []
    );

    // Enrich field information for paragraph fields.
    foreach ($fields as $field_name => &$field_info) {
      if ($bundle_fields[$field_name]['type'] == 'entity_reference_revisions') {
        $field_info['target_type_distribution'] = $this->getTargetTypeBundleDistribution($entity_type, $bundle, $bundle_instances, $field_name, $bundle_fields[$field_name]['target_type']);
      }
    }

    return $fields;
  }

  /**
   * Get distribution of target entity type bundles for field.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle type.
   * @param int $bundle_instances
   *   Number of bundle instances.
   * @param string $field_name
   *   The field name.
   * @param string $target_entity_type
   *   The target entity type for reference field.
   *
   * @return array
   *   Returns distribution of target entity bundles with required fields.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\Sql\SqlContentEntityStorageException
   */
  protected function getTargetTypeBundleDistribution($entity_type, $bundle, $bundle_instances, $field_name, $target_entity_type) {
    // TODO: Add caching of results.
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $this->entityTypeManager;

    /** @var \Drupal\Core\Entity\Sql\TableMappingInterface $entity_table_mapping */
    $entity_table_mapping = $entity_type_manager->getStorage($entity_type)
      ->getTableMapping();
    $field_ref_table = $entity_table_mapping->getFieldTableName($field_name);

    $target_type_definition = $entity_type_manager->getDefinition($target_entity_type);

    $query = $this->database->select($field_ref_table, 'field_t');
    // Order is important because we are using fetchAllKeyed with column index.
    $query->addExpression("target_entity_type_t.{$target_type_definition->getKey('bundle')}", 'target_bundle');
    $query->addExpression('count(*)', 'number_of_target_bundles');
    $query->innerJoin($target_type_definition->getBaseTable(), 'target_entity_type_t',
      "field_t.{$field_name}_target_id=target_entity_type_t.{$target_type_definition->getKey('id')}");
    $query->condition("field_t.bundle", $bundle);
    $query->groupBy('target_bundle');
    $query->orderBy('number_of_target_bundles', 'DESC');
    $results = $query->execute()->fetchAllKeyed(0, 1);

    $total_target_instances = array_sum($results);

    $target_types_per_instance = $total_target_instances / $bundle_instances;

    $number_of_target_bundles = array_map(function ($number_of_target_bundles) use ($total_target_instances, $target_types_per_instance) {
      $value = $number_of_target_bundles / $total_target_instances * $target_types_per_instance;
      return floor($value) != 0 ? floor($value) : ceil($value);
    }, $results);

    // Get fields for target bundles.
    $this->samplerMapping->enableMapping(FALSE);
    $target_entity_type_bundle_fields = $this->samplerPluginManager->createInstance("bundle:{$target_entity_type}")
      ->collect();
    foreach ($target_entity_type_bundle_fields as $target_bundle => &$target_bundle_fields) {
      // TODO: Improve filter of fields.
      // Fe. Image paragraphs does not required image to be selected.
      $target_bundle_fields['fields'] = array_filter($target_bundle_fields['fields'], function ($field_info) {
        return $field_info['required'];
      });

      $target_bundle_fields = array_keys($target_bundle_fields['fields']);
    }

    // Fill target bundles for the field.
    $target_type_instances = [];
    $target_types_per_instance = round($target_types_per_instance);
    foreach ($number_of_target_bundles as $target_bundle => $number_of_instances) {
      $target_type_instances[$target_bundle] = [
        'instances' => $number_of_instances,
        'fields' => $target_entity_type_bundle_fields[$target_bundle],
      ];
      $target_types_per_instance -= $number_of_instances;

      // The sack is full.
      if ($target_types_per_instance <= 0) {
        break;
      }
    }

    return $target_type_instances;
  }

  /**
   * Get bundles ordered by number of instances.
   *
   * @param array $data
   *   The collected data from sampler module for entity type.
   *
   * @return array
   *   Returns bundle names ordered by count.
   */
  protected function getBundlesByCount(array $data) {
    $bundles_by_count = [];

    foreach ($data as $bundle_name => $bundle_info) {
      if (!isset($bundle_info['instances'])) {
        continue;
      }

      $bundles_by_count[$bundle_name] = $bundle_info['instances'];
    }

    arsort($bundles_by_count);

    return $bundles_by_count;
  }

  /**
   * Get bundles ordered by number of instances.
   *
   * @param array $data
   *   The collected data from sampler module for entity type.
   *
   * @return array
   *   Returns bundle names ordered by count.
   */
  protected function getBundlesByNumberOfFields(array $data) {
    $bundles_by_number_of_fields = [];

    foreach ($data as $bundle_name => $bundle_info) {
      $bundles_by_number_of_fields[$bundle_name] = count(array_keys($bundle_info['fields']));
    }
    arsort($bundles_by_number_of_fields);

    return $bundles_by_number_of_fields;
  }

  /**
   * Gets site information.
   *
   * Allowed query options are following:
   * => rule: {string} - options:
   *   - count: to sort bundles by number of instance
   *   - number_of_fields: to sort bundles by number of fields
   * Default: "count"
   *
   * => index: {int}
   * Default: 0
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The symfony request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Returns JSON with site information needed by performance tests.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function siteInfo(Request $request) {
    $rule = $request->query->get('rule', 'count');
    $index = (int) $request->query->get('index', '0');
    $percent_of_instances_threshold = (int) $request->query->get('percent_of_instances_threshold', '101');

    // Validate request params.
    if (!in_array($rule, ['count', 'number_of_fields'])) {
      return new JsonResponse(
        [
          'message' => $this->t('Unsupported rule option.'),
        ],
        400
      );
    }

    $data = (array) $this->cache()
      ->get('thunder-performance-measurement:site-info:node');
    if (!isset($data['data'])) {
      $this->samplerMapping->enableMapping(FALSE);
      $data = $this->samplerPluginManager->createInstance('bundle:node')->collect();

      $bundles_by = [
        'count' => $this->getBundlesByCount($data),
        'number_of_fields' => $this->getBundlesByNumberOfFields($data),
      ];
      $data['bundles_by'] = $bundles_by;

      $this->cache()
        ->set('thunder-performance-measurement:site-info:node', $data);
    }
    else {
      $data = $data['data'];
    }

    $bundles_by_rule = array_keys($data['bundles_by'][$rule]);
    // Unsure that index is not out-of-bounds.
    if (count($bundles_by_rule) <= $index) {
      return new JsonResponse(
        [
          'message' => $this->t('Index out of bounds.'),
        ],
        400
      );
    }

    $bundle_name = $bundles_by_rule[$index];
    return new JsonResponse([
      'data' => [
        'bundle' => $bundle_name,
        'required_fields' => $this->getFieldWidgets('node', $bundle_name, $data[$bundle_name], $percent_of_instances_threshold),
      ],
    ]);
  }

}
