<?php

namespace Drupal\thunder_performance_measurement\Controller;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Keeps information about nested resolved references.
   *
   * @var array
   */
  protected $nestingDepth = [];

  /**
   * Constructs a new SiteInfoController object.
   *
   * @param \Drupal\sampler\SamplerPluginManager $sampler_plugin_manager
   *   The sampler plugin manager.
   * @param \Drupal\sampler\Mapping $sampler_mapping
   *   The sampler mapping service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   */
  public function __construct(SamplerPluginManager $sampler_plugin_manager, Mapping $sampler_mapping, EntityFieldManagerInterface $entity_field_manager) {
    $this->samplerPluginManager = $sampler_plugin_manager;
    $this->samplerMapping = $sampler_mapping;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.sampler'),
      $container->get('sampler.mapping'),
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
   * @param float $threshold
   *   The threshold in percents for non-required fields.
   *
   * @return mixed
   *   Returns fields for bundle.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function getFieldWidgets($entity_type, $bundle, array $bundle_info, $threshold = 100.0) {
    $entity_form_display = EntityFormDisplay::load("{$entity_type}.{$bundle}.default");
    $form_display_widgets = $entity_form_display->getComponents();

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    list('fields' => $bundle_fields, 'instances' => $bundle_instances) = $bundle_info;

    // Calculate percent of instances with filled field for bundle fields.
    foreach ($bundle_fields as $field_name => $field_info) {
      $bundle_fields[$field_name]['percent_of_instances'] = $bundle_instances == 0 ? 100.0 : (array_sum($field_info['histogram']) / $bundle_instances * 100);
    }

    $fields = [];
    foreach (array_keys($form_display_widgets) as $field_name) {
      $field_display_info = $form_display_widgets[$field_name];

      // Skip fields that are not displayed on form.
      if (!isset($field_display_info['region']) || $field_display_info['region'] !== 'content') {
        continue;
      }

      // Ensure that fields defined in form display exists in bundle fields.
      if (!isset($field_definitions[$field_name])) {
        continue;
      }

      // Base fields don't have sampler data and we still have to handle them.
      $base_field = $field_definitions[$field_name]->getFieldStorageDefinition()
        ->isBaseField();

      // Skip if we have field without information generated by sampler plugin.
      if (!$base_field && !isset($bundle_fields[$field_name])) {
        continue;
      }

      // Field information provided by sampler plugin.
      $field_info = $base_field ? ['type' => '_base', 'percent_of_instances' => 100.0] : $bundle_fields[$field_name];

      // Include required fields and fields over provided threshold.
      if (!$field_definitions[$field_name]->isRequired() && $field_info['percent_of_instances'] < $threshold) {
        continue;
      }

      // Add field information.
      $fields[$field_name] = $field_display_info;

      // Add target type distribution.
      if ($field_info['type'] == 'entity_reference_revisions' || $field_info['type'] == 'entity_reference') {
        // Accept only first level of nesting.
        if (!empty($this->nestingDepth)) {
          continue;
        }

        $nestingDepthKey = "{$entity_type}{$bundle}{$field_name}";
        $this->nestingDepth[$nestingDepthKey] = TRUE;

        $fields[$field_name]['target_type_distribution'] = $this->getTargetTypeBundleDistribution(
          $field_info['target_type_histogram'],
          $bundle_instances,
          $this->getTargetEntityFieldWidgets($field_info['target_type'], $threshold)
        );

        unset($this->nestingDepth[$nestingDepthKey]);
      }
    }

    return $fields;
  }

  /**
   * Get distribution of target entity type bundles for reference field.
   *
   * @param array $target_type_histogram
   *   The histogram for target entity type.
   * @param int $bundle_instances
   *   Number of bundle instances.
   * @param array $target_entity_type_bundle_fields
   *   The list of bundles with fields for target entity type.
   *
   * @return array
   *   Returns distribution of target entity bundles with relevant fields.
   */
  protected function getTargetTypeBundleDistribution(array $target_type_histogram, $bundle_instances, array $target_entity_type_bundle_fields) {
    $total_target_instances = array_sum($target_type_histogram);
    $target_types_per_instance = $bundle_instances === 0 ? 0 : $total_target_instances / $bundle_instances;

    $number_of_target_bundles = array_map(function ($number_of_target_bundles) use ($total_target_instances, $target_types_per_instance) {
      $value = $number_of_target_bundles / $total_target_instances * $target_types_per_instance;

      return floor($value) ?: 1;
    }, $target_type_histogram);

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
   * Get target entity type field widgets.
   *
   * @param string $target_entity_type
   *   The target entity type.
   * @param float $threshold
   *   The threshold limit.
   * @param string $form_mode
   *   The form display mode.
   *
   * @return mixed
   *   Returns bundles with fields for target entity type.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function getTargetEntityFieldWidgets($target_entity_type, $threshold, $form_mode = 'default') {
    try {
      // Get fields for target bundles.
      $target_entity_type_bundle_fields = $this->samplerPluginManager
        ->createInstance("bundle:{$target_entity_type}")
        ->collect();
    }
    catch (PluginException $e) {
      // No fields will be used when target entity type plugin does not exist.
      return [];
    }

    // Filter only fields in provided threshold.
    foreach ($target_entity_type_bundle_fields as $target_bundle => $target_bundle_info) {
      $target_entity_type_bundle_fields[$target_bundle] = [];

      // Add widget information for fields.
      $entity_form_display = EntityFormDisplay::load("{$target_entity_type}.{$target_bundle}.{$form_mode}");
      if (!$entity_form_display) {
        continue;
      }

      $target_bundle_instances = $target_bundle_info['instances'];
      foreach ($entity_form_display->getComponents() as $field_name => $field_display_info) {
        // Skip fields that are not displayed on form.
        if (!isset($field_display_info['region']) || $field_display_info['region'] !== 'content') {
          continue;
        }

        // Ensure that fields defined in form display exists in bundle fields.
        if (!isset($target_bundle_info['fields'][$field_name])) {
          continue;
        }

        $field_info = $target_bundle_info['fields'][$field_name];

        // Use field if number of instances is 0, otherwise check if field
        // usage is below threshold. This calculates threshold for all
        // instances of target entity type.
        if ($field_info['required'] || ($target_bundle_instances > 0 && (array_sum($field_info['histogram']) / $target_bundle_instances * 100) >= $threshold)) {
          $target_entity_type_bundle_fields[$target_bundle][$field_name] = $field_display_info;
        }

        // We are handling only first level of depth.
        if ($field_display_info['type'] == 'inline_entity_form_simple' && $field_info['type'] == 'entity_reference') {
          // Skip fields with no or more then one target bundle.
          if (count($field_info['target_bundles']) !== 1) {
            continue;
          }

          $target_type_sampler_plugin_data = $this->getDataForEntityType($field_info['target_type']);
          if (!isset($target_type_sampler_plugin_data['bundles'][$field_info['target_bundles'][0]])) {
            continue;
          }

          $target_entity_type_bundle_fields[$target_bundle][$field_name]['inline_entity_form'] = $this->getFieldWidgets($field_info['target_type'], $field_info['target_bundles'][0], $target_type_sampler_plugin_data['bundles'][$field_info['target_bundles'][0]], $threshold);
        }
      }
    }

    return $target_entity_type_bundle_fields;
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
   * Get data collected by sampler plugin with additional ordering information.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return array
   *   Returns enriched collected data.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function getDataForEntityType($entity_type) {
    $cached_data = (array) $this->cache()
      ->get("thunder-performance-measurement:site-info:{$entity_type}");

    if (isset($cached_data['data'])) {
      return $cached_data['data'];
    }

    $data = $this->samplerPluginManager->createInstance("reference_fields_target_bundles:{$entity_type}")
      ->collect();

    $cached_data = [
      'bundles' => $data,
      'bundles_by' => [
        'count' => $this->getBundlesByCount($data),
        'number_of_fields' => $this->getBundlesByNumberOfFields($data),
      ],
    ];

    $this->cache()
      ->set("thunder-performance-measurement:site-info:{$entity_type}", $cached_data);

    return $cached_data;
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

    // TODO: set default value to 100 when we start using
    // TODO: percent_of_instances_threshold in tests.
    $percent_of_instances_threshold = (float) $request->query->get('percent_of_instances_threshold', '100.1');

    // Validate request params.
    if (!in_array($rule, ['count', 'number_of_fields'])) {
      return new JsonResponse(
        [
          'message' => $this->t('Unsupported rule option.'),
        ],
        400
      );
    }

    // Any sampler calls now on should be without mapping.
    $this->samplerMapping->enableMapping(FALSE);

    $data = $this->getDataForEntityType('node');

    $bundles_by_rule = array_keys($data['bundles_by'][$rule]);
    // Unsure that index is not out-of-bounds.
    if ($index < 0 || count($bundles_by_rule) <= $index) {
      return new JsonResponse(
        [
          'message' => $this->t('Index out of bounds.'),
        ],
        400
      );
    }

    $bundle_name = $bundles_by_rule[$index];
    $field_widgets = $this->getFieldWidgets('node', $bundle_name, $data['bundles'][$bundle_name], $percent_of_instances_threshold);
    return new JsonResponse([
      'data' => [
        'bundle' => $bundle_name,
        // TODO: remove required_fields when we start using new key in tests.
        'required_fields' => $field_widgets,
        'fields' => $field_widgets,
      ],
    ]);
  }

}
