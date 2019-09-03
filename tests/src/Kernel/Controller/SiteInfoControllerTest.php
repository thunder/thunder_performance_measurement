<?php

namespace Drupal\Tests\thunder_performance_measurement\Kernel\Controller;

use Drupal\KernelTests\KernelTestBase;
use Drupal\thunder_performance_measurement\Controller\SiteInfoController;
use ReflectionClass;

/**
 * Provides automated tests for the thunder_performance_measurement module.
 *
 * TODO: We need additional testing.
 *
 * @coversDefaultClass \Drupal\thunder_performance_measurement\Controller\SiteInfoController
 *
 * @group thunder_performance_measurement
 */
class SiteInfoControllerTest extends KernelTestBase {

  /**
   * Modules to enable for testing.
   *
   * @var array
   */
  protected static $modules = [
    'sampler',
  ];

  /**
   * The simple data representation of sampler result.
   */
  protected function getRelevantSamplerResultData() {
    return [
      'bundle_0' => [
        'fields' => [
          'field_0' => [],
          'field_1' => [],
          'field_2' => [],
        ],
        'instances' => 1,
      ],
      'bundle_1' => [
        'fields' => [
          'field_4' => [],
        ],
        'instances' => 2,
      ],

    ];
  }

  /**
   * Tests sorting of bundles by instance count.
   *
   * @covers ::getBundlesByCount
   */
  public function testGetBundlesByCount() {
    $controller = SiteInfoController::create($this->container);

    $controller_class = new ReflectionClass(get_class($controller));
    $get_bundles_by_count = $controller_class->getMethod('getBundlesByCount');
    $get_bundles_by_count->setAccessible(TRUE);

    $result = $get_bundles_by_count->invokeArgs($controller, [$this->getRelevantSamplerResultData()]);

    $this->assertEqual($result, ['bundle_1' => 2, 'bundle_0' => 1]);
  }

  /**
   * Tests sorting of bundles by number of fields.
   *
   * @covers ::getBundlesByNumberOfFields
   */
  public function testGetBundlesByNumberOfFields() {
    $controller = SiteInfoController::create($this->container);

    $controller_class = new ReflectionClass(get_class($controller));
    $get_bundles_by_number_of_fields = $controller_class->getMethod('getBundlesByNumberOfFields');
    $get_bundles_by_number_of_fields->setAccessible(TRUE);

    $result = $get_bundles_by_number_of_fields->invokeArgs($controller, [$this->getRelevantSamplerResultData()]);

    $this->assertEqual($result, ['bundle_0' => 3, 'bundle_1' => 1]);
  }

}
