<?php

namespace Drupal\Tests\thunder_performance_measurement\Kernel\Plugin\Sampler;

use Drupal\Tests\thunder_performance_measurement\Kernel\ThunderPerformanceMeasurementTestBase;

/**
 * Test for ReferenceFieldTargetBundles class.
 *
 * @coversDefaultClass \Drupal\thunder_performance_measurement\Plugin\Sampler\ReferenceFieldTargetBundles
 *
 * @group thunder_performance_measurement
 */
class ReferenceFieldTargetBundlesTest extends ThunderPerformanceMeasurementTestBase {

  /**
   * The reference field target bundles plugin.
   *
   * @var \Drupal\thunder_performance_measurement\Plugin\Sampler\ReferenceFieldTargetBundles
   */
  protected $referenceFieldTargetBundlesPlugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->referenceFieldTargetBundlesPlugin = \Drupal::service('plugin.manager.sampler')->createInstance("reference_fields_target_bundles:node");

    // Disable sampler mapping.
    \Drupal::service('sampler.mapping')->enableMapping(FALSE);
  }

  /**
   * Testing of reference field target bundles.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function test() {
    $this->createTestContent();

    $data = $this->referenceFieldTargetBundlesPlugin->collect();
    $fields = $data['type_one']['fields'];

    // Check collected data.
    $this->assertEqual($fields['node_references']['target_bundles'], ['type_one']);
    $this->assertEqual($fields['node_references']['target_type_histogram'], ['type_one' => 2]);

    $this->assertEqual($fields['paragraphs']['target_bundles'], [
      'one',
      'two',
      'three',
    ]);
    $this->assertEqual($fields['paragraphs']['target_type_histogram'], [
      'one' => 1,
      'two' => 2,
    ]);
  }

}
