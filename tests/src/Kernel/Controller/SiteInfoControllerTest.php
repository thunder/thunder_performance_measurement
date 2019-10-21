<?php

namespace Drupal\Tests\thunder_performance_measurement\Kernel\Controller;

use Drupal\thunder_performance_measurement\Controller\SiteInfoController;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Tests\thunder_performance_measurement\Kernel\ThunderPerformanceMeasurementTestBase;

/**
 * Provides automated tests for the thunder_performance_measurement module.
 *
 * TODO: We need additional testing.
 *
 * @coversDefaultClass \Drupal\thunder_performance_measurement\Controller\SiteInfoController
 *
 * @group thunder_performance_measurement
 */
class SiteInfoControllerTest extends ThunderPerformanceMeasurementTestBase {

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

  /**
   * Data provider for testGetTargetTypeBundleDistribution().
   */
  public function providerGetTargetTypeBundleDistribution() {
    $target_entity_field_widgets_data = [
      'target_bundle_text' => [
        'field_text' => [
          'type' => 'text_textarea',
        ],
      ],
      'target_bundle_image' => [
        'field_image' => [
          'type' => 'entity_browser_entity_reference',
        ],
        'field_description' => [
          'type' => 'text_textarea',
        ],
      ],
      'target_bundle_link' => [
        'field_media' => [
          'type' => 'inline_entity_form_simple',
        ],
      ],
    ];

    return [
      // In case of single instance, the full list of target entity type bundles
      // should be returned.
      [
        [
          'target_bundle_text' => 6,
          'target_bundle_image' => 2,
          'target_bundle_link' => 2,
        ],
        1,
        $target_entity_field_widgets_data,
        [
          'target_bundle_text' => [
            'instances' => 6,
            'fields' => [
              'field_text' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
          'target_bundle_image' => [
            'instances' => 2,
            'fields' => [
              'field_image' => [
                'type' => 'entity_browser_entity_reference',
              ],
              'field_description' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
          'target_bundle_link' => [
            'instances' => 2,
            'fields' => [
              'field_media' => [
                'type' => 'inline_entity_form_simple',
              ],
            ],
          ],
        ],
      ],
      // Only used target bundles should be returned.
      [
        [
          'target_bundle_text' => 6,
          'target_bundle_link' => 2,
        ],
        1,
        $target_entity_field_widgets_data,
        [
          'target_bundle_text' => [
            'instances' => 6,
            'fields' => [
              'field_text' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
          'target_bundle_link' => [
            'instances' => 2,
            'fields' => [
              'field_media' => [
                'type' => 'inline_entity_form_simple',
              ],
            ],
          ],
        ],
      ],
      // For two instances, number of instances for target entity bundles
      // should be half.
      [
        [
          'target_bundle_text' => 6,
          'target_bundle_image' => 2,
          'target_bundle_link' => 2,
        ],
        2,
        $target_entity_field_widgets_data,
        [
          'target_bundle_text' => [
            'instances' => 3,
            'fields' => [
              'field_text' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
          'target_bundle_image' => [
            'instances' => 1,
            'fields' => [
              'field_image' => [
                'type' => 'entity_browser_entity_reference',
              ],
              'field_description' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
          'target_bundle_link' => [
            'instances' => 1,
            'fields' => [
              'field_media' => [
                'type' => 'inline_entity_form_simple',
              ],
            ],
          ],
        ],
      ],
      // For rarely filled field we should have most used bundle in list of
      // target entity type bundles.
      [
        [
          'target_bundle_text' => 6,
          'target_bundle_image' => 2,
          'target_bundle_link' => 2,
        ],
        50,
        $target_entity_field_widgets_data,
        [
          'target_bundle_text' => [
            'instances' => 1,
            'fields' => [
              'field_text' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
        ],
      ],
      // In case of histogram where some bundles are starting to drop off.
      [
        [
          'target_bundle_text' => 80,
          'target_bundle_link' => 20,
          'target_bundle_image' => 1,
        ],
        20,
        $target_entity_field_widgets_data,
        [
          'target_bundle_text' => [
            'instances' => 4,
            'fields' => [
              'field_text' => [
                'type' => 'text_textarea',
              ],
            ],
          ],
          'target_bundle_link' => [
            'instances' => 1,
            'fields' => [
              'field_media' => [
                'type' => 'inline_entity_form_simple',
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Tests sorting of bundles by number of fields.
   *
   * @covers ::getTargetTypeBundleDistribution
   *
   * @dataProvider providerGetTargetTypeBundleDistribution
   */
  public function testGetTargetTypeBundleDistribution($target_type_histogram, $bundle_instances, $target_entity_field_widgets_data, $expected_result) {
    $controller = SiteInfoController::create($this->container);

    $controller_class = new ReflectionClass(get_class($controller));
    $get_target_type_bundle_distribution = $controller_class->getMethod('getTargetTypeBundleDistribution');
    $get_target_type_bundle_distribution->setAccessible(TRUE);

    $result = $get_target_type_bundle_distribution->invokeArgs($controller, [
      $target_type_histogram,
      $bundle_instances,
      $target_entity_field_widgets_data,
    ]);

    $this->assertEqual(
      $result,
      $expected_result
    );
  }

  /**
   * Test the fetching of field widgets.
   *
   * @covers ::getFieldWidgets
   */
  public function testGetFieldWidgets() {
    $this->createTestContent();

    $controller = SiteInfoController::create($this->container);

    // Test empty request - default values should be used.
    $result = $controller->siteInfo(new Request());
    $this->assertEqual($result->getStatusCode(), 200);

    // Test invalid params in request.
    $result = $controller->siteInfo(new Request(['rule' => '_not_defined']));
    $this->assertEqual($result->getStatusCode(), 400);

    $result = $controller->siteInfo(new Request(['index' => '-1']));
    $this->assertEqual($result->getStatusCode(), 400);

    $result = $controller->siteInfo(new Request(['index' => '100000']));
    $this->assertEqual($result->getStatusCode(), 400);

    // Test defined rules and index.
    $result = $controller->siteInfo(new Request(['rule' => 'count', 'index' => '0']));
    $this->assertEqual($result->getStatusCode(), 200);
    $json_result_count_index_0 = json_decode($result->getContent(), TRUE);
    $this->assertEqual($json_result_count_index_0['data']['bundle'], 'type_one');
    $this->assertArrayHasKey('title', $json_result_count_index_0['data']['required_fields']);

    $result = $controller->siteInfo(new Request(['rule' => 'number_of_fields', 'index' => '1']));
    $this->assertEqual($result->getStatusCode(), 200);
    $json_result_number_of_fields_index_1 = json_decode($result->getContent(), TRUE);
    $this->assertEqual($json_result_number_of_fields_index_1['data']['bundle'], 'type_two');
    $this->assertArrayHasKey('title', $json_result_number_of_fields_index_1['data']['required_fields']);

    // Test threshold where all displayed fields are available.
    // (rule = count, index = 0)
    $result = $controller->siteInfo(new Request(['percent_of_instances_threshold' => '0']));
    $this->assertEqual($result->getStatusCode(), 200);
    $json_result_percent_of_instances_threshold_0 = json_decode($result->getContent(), TRUE);
    $this->assertEqual($json_result_percent_of_instances_threshold_0['data']['bundle'], 'type_one');
    $this->assertArrayHasKey('paragraphs', $json_result_percent_of_instances_threshold_0['data']['required_fields']);
    $this->assertArrayNotHasKey('node_references', $json_result_percent_of_instances_threshold_0['data']['required_fields']);

    // Test threshold for 30% filled fields. (rule = count, index = 0)
    $result = $controller->siteInfo(new Request(['percent_of_instances_threshold' => '30']));
    $this->assertEqual($result->getStatusCode(), 200);
    $json_result_percent_of_instances_threshold_30 = json_decode($result->getContent(), TRUE);
    $this->assertEqual($json_result_percent_of_instances_threshold_30['data']['bundle'], 'type_one');
    $this->assertArrayHasKey('paragraphs', $json_result_percent_of_instances_threshold_30['data']['required_fields']);

    // Test threshold for 100% filled fields. (rule = count, index = 0)
    $result = $controller->siteInfo(new Request(['percent_of_instances_threshold' => '100']));
    $this->assertEqual($result->getStatusCode(), 200);
    $json_result_percent_of_instances_threshold_100 = json_decode($result->getContent(), TRUE);
    $this->assertEqual($json_result_percent_of_instances_threshold_100['data']['bundle'], 'type_one');
    $this->assertArrayNotHasKey('paragraphs', $json_result_percent_of_instances_threshold_100['data']['required_fields']);
  }

}
