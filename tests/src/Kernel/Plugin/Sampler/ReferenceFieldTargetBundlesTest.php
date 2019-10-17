<?php

namespace Drupal\Tests\thunder_performance_measurement\Kernel\Plugin\Sampler;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Test for ReferenceFieldTargetBundles class.
 *
 * @coversDefaultClass \Drupal\thunder_performance_measurement\Plugin\Sampler\ReferenceFieldTargetBundles
 *
 * @group thunder_performance_measurement
 */
class ReferenceFieldTargetBundlesTest extends KernelTestBase {

  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  public static $modules = [
    'field',
    'node',
    'user',
    'system',
    'file',
    'entity_reference_revisions',
    'paragraphs',
    'sampler',
    'thunder_performance_measurement',
    'thunder_performance_measurement_test',
  ];

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

    $this->installConfig(['sampler', 'thunder_performance_measurement_test']);

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');

    $this->referenceFieldTargetBundlesPlugin = \Drupal::service('plugin.manager.sampler')->createInstance("reference_fields_target_bundles:node");

    // Disable sampler mapping.
    \Drupal::service('sampler.mapping')->enableMapping(FALSE);
  }

  /**
   * Create paragraphs with provided paragraph types.
   *
   * @param array $types
   *   List of paragraph types.
   *
   * @return array
   *   Returns list of created paragraphs.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createParagraphs(array $types) {
    $paragraphs = [];
    foreach ($types as $type) {
      $paragraph = Paragraph::create([
        'type' => $type,
      ]);
      $paragraph->save();

      array_push($paragraphs, $paragraph);
    }

    return $paragraphs;
  }

  /**
   * Testing of reference field target bundles.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function test() {
    // Create some content, before collecting data.
    $node_1 = Node::create([
      'type' => 'type_one',
      'title' => $this->randomString(),
    ]);
    $node_1->save();

    // Create new content with references.
    Node::create([
      'type' => 'type_one',
      'title' => $this->randomString(),
      'field_node' => [$node_1->id()],
    ])->save();

    // Create new node type with paragraphs.
    Node::create([
      'type' => 'type_one',
      'title' => $this->randomString(),
      'field_node' => [$node_1->id()],
      'field_paragraphs' => $this->createParagraphs(['one', 'two', 'two']),
    ])->save();

    $data = $this->referenceFieldTargetBundlesPlugin->collect();
    $fields = $data['type_one']['fields'];

    // Check collected data.
    $this->assertEqual($fields['field_node']['target_bundles'], ['type_one']);
    $this->assertEqual($fields['field_node']['target_type_histogram'], ['type_one' => 2]);

    $this->assertEqual($fields['field_paragraphs']['target_bundles'], [
      'one',
      'two',
      'three',
    ]);
    $this->assertEqual($fields['field_paragraphs']['target_type_histogram'], [
      'one' => 1,
      'two' => 2,
    ]);
  }

}
