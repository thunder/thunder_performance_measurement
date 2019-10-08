<?php

namespace Drupal\thunder_performance_measurement\Controller;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
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
   * Constructs a new SiteInfoController object.
   *
   * @param \Drupal\sampler\SamplerPluginManager $sampler_plugin_manager
   *   The sampler plugin manager.
   * @param \Drupal\sampler\Mapping $sampler_mapping
   *   The sampler mapping service.
   */
  public function __construct(SamplerPluginManager $sampler_plugin_manager, Mapping $sampler_mapping) {
    $this->samplerPluginManager = $sampler_plugin_manager;
    $this->samplerMapping = $sampler_mapping;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.sampler'),
      $container->get('sampler.mapping')
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
   *   Returns all required fields for bundle.
   */
  protected function getFieldWidgets($entity_type, $bundle, array $bundle_info, $threshold = 100.0) {
    $entity_form_display = EntityFormDisplay::load("{$entity_type}.{$bundle}.default");
    $form_display_widgets = $entity_form_display->getComponents();

    list('fields' => $bundle_fields, 'instances' => $bundle_instances) = $bundle_info;

    // Calculate percent of instances with filled field for bundle fields.
    foreach ($bundle_fields as &$field_info) {
      $field_info['percent_of_instances'] = $bundle_instances == 0 ? 100.0 : (array_sum($field_info['histogram']) / $bundle_instances * 100);
    }

    $fields = array_reduce(
      array_keys($form_display_widgets),
      function ($collection, $field_name) use ($form_display_widgets, $bundle_fields, $threshold, $bundle_instances) {
        $field_display_info = $form_display_widgets[$field_name];

        // Skip fields that are not displayed on form.
        if (!isset($field_display_info['region']) || $field_display_info['region'] !== 'content') {
          return $collection;
        }

        // Ensure that fields defined in form display exists in bundle fields.
        if (!isset($bundle_fields[$field_name])) {
          return $collection;
        }

        // Field information provided by sampler plugin.
        $field_info = $bundle_fields[$field_name];

        // Include required fields and fields over provided threshold.
        if (!$field_info['required'] && $field_info['percent_of_instances'] < $threshold) {
          return $collection;
        }

        // Add field information.
        $collection[$field_name] = $field_display_info;

        // Add target type distribution.
        if ($field_info['type'] == 'entity_reference_revisions' || $field_info['type'] == 'entity_reference') {
          $collection[$field_name]['target_type_distribution'] = $this->getTargetTypeBundleDistribution($field_info, $bundle_instances, $threshold);
        }

        return $collection;
      },
      []
    );

    return $fields;
  }

  /**
   * Get distribution of target entity type bundles for reference field.
   *
   * @param array $field_info
   *   Gathered field information by sampler plugin.
   * @param int $bundle_instances
   *   Number of bundle instances.
   * @param float $threshold
   *   The threshold in percents for non-required fields.
   *
   * @return array
   *   Returns distribution of target entity bundles with required fields.
   */
  protected function getTargetTypeBundleDistribution(array $field_info, $bundle_instances, $threshold = 100.0) {
    $target_entity_type = $field_info['target_type'];
    $target_type_histogram = $field_info['target_type_histogram'];

    $total_target_instances = array_sum($target_type_histogram);
    $target_types_per_instance = $bundle_instances === 0 ? 0 : $total_target_instances / $bundle_instances;

    $number_of_target_bundles = array_map(function ($number_of_target_bundles) use ($total_target_instances, $target_types_per_instance) {
      $value = $number_of_target_bundles / $total_target_instances * $target_types_per_instance;

      return floor($value) != 0 ? floor($value) : ceil($value);
    }, $target_type_histogram);

    // Get fields for target bundles.
    $target_entity_type_bundle_fields = $this->getTargetEntityFieldWidgets($target_entity_type, $threshold);

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
   *
   * @return mixed
   *   Returns bundles with fields for target entity type.
   */
  protected function getTargetEntityFieldWidgets($target_entity_type, $threshold) {
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
    foreach ($target_entity_type_bundle_fields as $target_bundle => &$target_bundle_info) {
      $target_bundle_instances = $target_bundle_info['instances'];

      // Add widget information for fields.
      $entity_form_display = EntityFormDisplay::load("{$target_entity_type}.{$target_bundle}.default");
      if (!$entity_form_display) {
        $target_bundle_info = [];

        continue;
      }

      $form_display_widgets = array_filter(
        $entity_form_display->getComponents(),
        function ($field_display_info, $field_name) use ($target_bundle_info, $target_bundle_instances, $threshold) {
          // Skip fields that are not displayed on form.
          if (!isset($field_display_info['region']) || $field_display_info['region'] !== 'content') {
            return FALSE;
          }

          // Ensure that fields defined in form display exists in bundle fields.
          if (!isset($target_bundle_info['fields'][$field_name])) {
            return FALSE;
          }

          $field_info = $target_bundle_info['fields'][$field_name];
          if ($field_info['required']) {
            return TRUE;
          }

          // Use field if number of instances is 0, otherwise check if field
          // usage is below threshold. This calculates threshold for all
          // instances of target entity type.
          return $target_bundle_instances == 0 || (array_sum($field_info['histogram']) / $target_bundle_instances * 100) >= $threshold;
        },
        ARRAY_FILTER_USE_BOTH
      );

      $target_bundle_info = $form_display_widgets;
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

    $data = (array) $this->cache()
      ->get('thunder-performance-measurement:site-info:node');
    if (!isset($data['data'])) {
      $data = $this->samplerPluginManager->createInstance('reference_fields_target_bundles:node')->collect();

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
    $required_fields = $this->getFieldWidgets('node', $bundle_name, $data[$bundle_name], $percent_of_instances_threshold);
    return new JsonResponse([
      'data' => [
        'bundle' => $bundle_name,
        'required_fields' => $required_fields,
      ],
    ]);
  }

}
