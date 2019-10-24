<?php

namespace Drupal\Tests\thunder_performance_measurement\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Base class for thunder performance measurement tests.
 */
class ThunderPerformanceMeasurementTestBase extends KernelTestBase {

  /**
   * Modules to enable for testing.
   *
   * @var array
   */
  protected static $modules = [
    'field',
    'file',
    'node',
    'path',
    'user',
    'system',
    'entity_reference_revisions',
    'paragraphs',
    'sampler',
    'thunder_performance_measurement',
    'thunder_performance_measurement_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installConfig(['sampler', 'thunder_performance_measurement_test']);

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
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
   * Create test content.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createTestContent() {
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
      'node_references' => [$node_1->id()],
    ])->save();

    // Create new node type with paragraphs.
    Node::create([
      'type' => 'type_one',
      'title' => $this->randomString(),
      'node_references' => [$node_1->id()],
      'paragraphs' => $this->createParagraphs(['one', 'two', 'two']),
    ])->save();
  }

}
