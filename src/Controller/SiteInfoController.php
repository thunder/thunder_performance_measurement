<?php

namespace Drupal\thunder_performance_measurement\Controller;

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
   * Get required fields for bundle.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   *
   * @return mixed
   *   Returns all required fields for bundle.
   */
  protected function getRequiredFieldWidgets($entity_type, $bundle) {
    $entity_form_display = EntityFormDisplay::load("{$entity_type}.{$bundle}.default");
    $form_display_widgets = $entity_form_display->getComponents();

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $required_fields = array_reduce(
      array_keys($form_display_widgets),
      function ($collection, $field_name) use ($form_display_widgets, $field_definitions) {
        $field_info = $form_display_widgets[$field_name];
        if (!isset($field_info['region']) || $field_info['region'] !== 'content') {
          return $collection;
        }

        if (isset($field_definitions[$field_name]) && $field_definitions[$field_name]->isRequired()) {
          $collection[$field_name] = $field_info;
        }

        return $collection;
      },
      []
    );

    return $required_fields;
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

    $bundles = array_keys($data['bundles_by'][$rule]);
    // Unsure that index is not out-of-bounds.
    if (count($bundles) <= $index) {
      return new JsonResponse(
        [
          'message' => $this->t('Index out of bounds.'),
        ],
        400
      );
    }

    return new JsonResponse([
      'data' => [
        'bundle' => $bundles[$index],
        'required_fields' => $this->getRequiredFieldWidgets('node', $bundles[$index]),
      ],
    ]);
  }

}
