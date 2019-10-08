<?php

namespace Drupal\thunder_performance_measurement\Plugin\Sampler;

use Drupal\sampler\Plugin\Sampler\Bundle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Collects target entity type bundle distribution for reference fields.
 *
 * @Sampler(
 *   id = "reference_fields_target_bundles",
 *   label = @Translation("Reference fields target bundles"),
 *   description = @Translation("Collects reference fields target bundles for bundle."),
 *   deriver = "\Drupal\sampler\Plugin\Derivative\BundleDeriver"
 * )
 */
class ReferenceFieldTargetBundles extends Bundle {

  /**
   * Keeps results of getReferenceFieldTargetBundlesHistogram.
   *
   * @var array
   */
  protected $referenceFieldTargetBundlesHistogram = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('sampler.mapping'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('database'),
      $container->get('sampler.field_data'),
      $container->get('config.factory')->get('sampler.settings')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function collect() {
    parent::collect();

    $entityTypeId = $this->entityTypeId();

    foreach ($this->collectedData as $bundle => &$bundle_info) {
      /** @var \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition */
      foreach ($bundle_info['fields'] as $fieldName => &$fieldData) {
        if ($fieldData['type'] == 'entity_reference_revisions' || $fieldData['type'] == 'entity_reference') {
          $fieldData['target_type_histogram'] = $this->getReferenceFieldTargetBundlesHistogram(
            $entityTypeId,
            $bundle,
            $fieldName,
            $fieldData['target_type']
          );
        }
      }
    }

    return $this->collectedData;
  }

  /**
   * Get histogram of target bundles for reference field.
   *
   * @param string $entity_type
   *   The entity type with reference field.
   * @param string $bundle
   *   The bundle with reference field.
   * @param string $field_name
   *   The field name.
   * @param string $target_entity_type
   *   The target entity type for the field.
   *
   * @return array
   *   Target entity type histogram for field.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\Sql\SqlContentEntityStorageException
   */
  protected function getReferenceFieldTargetBundlesHistogram($entity_type, $bundle, $field_name, $target_entity_type) {
    $resultKey = "{$entity_type}.{$bundle}.{$field_name}.{$target_entity_type}";

    // Use cached value.
    if (isset($this->referenceFieldTargetBundlesHistogram[$resultKey])) {
      return $this->referenceFieldTargetBundlesHistogram[$resultKey];
    }

    // Ensure we have bundles for the target entity type.
    $target_type_definition = $this->entityTypeManager->getDefinition($target_entity_type);
    if (!$target_type_definition->getKey('bundle')) {
      $this->referenceFieldTargetBundlesHistogram[$resultKey] = [];

      return $this->referenceFieldTargetBundlesHistogram[$resultKey];
    }

    /** @var \Drupal\Core\Entity\Sql\TableMappingInterface $entity_table_mapping */
    $entity_table_mapping = $this->entityTypeManager->getStorage($entity_type)
      ->getTableMapping();
    $field_ref_table = $entity_table_mapping->getFieldTableName($field_name);

    // Create custom query for grouping.
    $query = $this->connection->select($field_ref_table, 'field_t');

    // Order is important because we are using fetchAllKeyed with column index.
    $query->addExpression("target_entity_type_t.{$target_type_definition->getKey('bundle')}", 'target_bundle');
    $query->addExpression('count(*)', 'number_of_target_bundles');

    $query->innerJoin($target_type_definition->getBaseTable(), 'target_entity_type_t',
      "field_t.{$field_name}_target_id=target_entity_type_t.{$target_type_definition->getKey('id')}");
    $query->condition('field_t.bundle', $bundle);

    $query->groupBy('target_bundle');
    $query->orderBy('number_of_target_bundles', 'DESC');

    $data = $query->execute()->fetchAllKeyed(0, 1);

    $this->referenceFieldTargetBundlesHistogram[$resultKey] = $data;

    return $this->referenceFieldTargetBundlesHistogram[$resultKey];
  }

}
