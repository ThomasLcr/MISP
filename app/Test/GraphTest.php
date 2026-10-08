<?php
/**
 * Analyst graphs: the document validator and its caps, edit rights, the
 * audit summary, and graphs staying out of embedded analyst data.
 *
 * Pure PHPUnit, no CakePHP bootstrap and no database, as the other tests
 * under app/Test/. The framework classes touched are stubbed below, each
 * guarded so that a full-suite run sharing one process keeps whichever stub
 * loaded first.
 */

require_once __DIR__ . '/../Vendor/autoload.php';

use PHPUnit\Framework\TestCase;

if (!class_exists('App', false)) {
    class App
    {
        public static function uses($class, $package)
        {
        }
    }
}

if (!class_exists('Configure', false)) {
    class Configure
    {
        private static $values = array();

        public static function read($key)
        {
            return isset(self::$values[$key]) ? self::$values[$key] : null;
        }

        public static function check($key)
        {
            return isset(self::$values[$key]);
        }

        public static function write($key, $value)
        {
            self::$values[$key] = $value;
        }
    }
}

if (!class_exists('ClassRegistry', false)) {
    class ClassRegistry
    {
        public static $instances = array();

        public static function init($name)
        {
            if (!isset(self::$instances[$name])) {
                throw new RuntimeException("No fake registered for $name.");
            }
            return self::$instances[$name];
        }
    }
}

if (!function_exists('__')) {
    function __($string)
    {
        $args = func_get_args();
        $format = array_shift($args);
        return empty($args) ? $format : vsprintf($format, $args);
    }
}

if (!class_exists('AppModel', false)) {
    class AppModel
    {
        public $id = false;
        public $data = array();
        public $validationErrors = array();
    }
}

if (!class_exists('Model', false)) {
    class Model
    {
    }
}

if (!class_exists('ModelBehavior', false)) {
    class ModelBehavior
    {
        public function __construct()
        {
        }
    }
}

if (!class_exists('Validation', false)) {
    class Validation
    {
        public static function uuid($check)
        {
            return is_string($check) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[0-5][a-f0-9]{3}-[089ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $check);
        }
    }
}

if (!class_exists('AuditLog', false)) {
    class AuditLog
    {
        const ACTION_ADD = 'add';
        const ACTION_EDIT = 'edit';
        const ACTION_SOFT_DELETE = 'soft_delete';
        const ACTION_UNDELETE = 'undelete';
        const ACTION_DELETE = 'delete';
    }
}

require_once __DIR__ . '/../Model/Value.php';
require_once __DIR__ . '/../Lib/Tools/AnalystGraphDocumentTool.php';
require_once __DIR__ . '/../Model/AnalystData.php';
require_once __DIR__ . '/../Model/Graph.php';
require_once __DIR__ . '/../Model/Behavior/AnalystDataParentBehavior.php';
require_once __DIR__ . '/../Model/Behavior/AuditLogBehavior.php';
require_once __DIR__ . '/../Lib/Tools/ValueProfile/ValueUrlTool.php';
require_once __DIR__ . '/../Model/AnalystGraphData.php';

class GraphTestGraph extends Graph
{
    public $alias = 'Graph';
    public $name = 'Graph';

    public function __construct()
    {
    }
}

class GraphTestModel extends Model
{
    public $name = 'Graph';
    public $alias = 'Graph';
    public $primaryKey = 'id';
    public $id = 1;
    public $data = array();
    public $includeAnalystDataRecursive = false;
    public $graph;

    public function __construct()
    {
        $this->graph = new GraphTestGraph();
    }

    public function schema($field = false)
    {
        $fields = array();
        foreach (array('id', 'content_size', 'node_count', 'revision') as $name) {
            $fields[$name] = array('type' => 'integer');
        }
        foreach (array('uuid', 'name', 'content') as $name) {
            $fields[$name] = array('type' => 'string');
        }
        return $fields;
    }

    public function auditLogSummary($field, $old, $new)
    {
        $this->graph->data = $this->data;
        return $this->graph->auditLogSummary($field, $old, $new);
    }
}

class GraphTestRecorder
{
    public $calls = array();
    public $fetchRecursive;

    public function fetchForUuids($uuids, $user = null)
    {
        $this->calls[] = $uuids;
        return array();
    }

    public function insert(array $row)
    {
        $this->calls[] = $row;
    }
}

class GraphTestDataSource
{
    public $log = array();
    public $rows = array();

    public function begin()
    {
        $this->log[] = 'begin';
        return true;
    }

    public function commit()
    {
        $this->log[] = 'commit';
        return true;
    }

    public function rollback()
    {
        $this->log[] = 'rollback';
        return true;
    }

    public function name($field)
    {
        return '`' . $field . '`';
    }

    public function fullTableName($model)
    {
        return '`analyst_graphs`';
    }

    public function fetchAll($sql, $params = array(), $options = array())
    {
        $this->log[] = array($sql, $params, $options);
        return $this->rows;
    }
}

class GraphTestWritableGraph extends GraphTestGraph
{
    public $db;
    public $saves = array();
    public $refuse = false;

    public function getDataSource()
    {
        return $this->db;
    }

    public function create($data = array())
    {
        $this->id = false;
        $this->data = array();
    }

    public function save($data = null, $validate = true, $fieldList = array())
    {
        $this->saves[] = array($data, $validate);
        if ($this->refuse) {
            $this->validationErrors = array('content' => array('nodes[0]: unknown node type.'));
            return false;
        }
        $this->db->log[] = 'save';
        return array('Graph' => $data['Graph'] + array('revision' => 6, 'node_count' => 1, 'content_size' => 99));
    }
}

class GraphTestActiveGraph extends GraphTestGraph
{
    public $summaryCalls = array();
    public $summaryRows = array();

    public function summaries(array $user, array $conditions, array $options = array())
    {
        $this->summaryCalls[] = array($conditions, $options);
        return $this->summaryRows;
    }
}

class GraphTestCountedGraph extends GraphTestGraph
{
    public $contents = array();
    public $asked = array();

    public function storedContents(array $ids)
    {
        $this->asked[] = $ids;
        return array_intersect_key($this->contents, array_flip($ids));
    }
}

class GraphTestBehaviors
{
    public $log = array();
    private $on;

    public function __construct($on)
    {
        $this->on = $on;
    }

    public function enabled($name)
    {
        return $this->on;
    }

    public function disable($name)
    {
        $this->log[] = array('disable', $name);
    }

    public function enable($name)
    {
        $this->log[] = array('enable', $name);
    }
}

/**
 * A model fake answering whatever the test registers for each method.
 */
class GraphTestFake
{
    public $calls = array();
    private $handlers = array();

    public function on($method, callable $handler)
    {
        $this->handlers[$method] = $handler;
        return $this;
    }

    public function __call($method, $args)
    {
        $this->calls[] = array($method, $args);
        if (!isset($this->handlers[$method])) {
            throw new RuntimeException("No handler for $method.");
        }
        return call_user_func_array($this->handlers[$method], $args);
    }
}

class GraphTestUserSetting extends GraphTestFake
{
    public $Behaviors;
}

class GraphTest extends TestCase
{
    const ATTRIBUTE = '5D34E7F4-0000-4000-8000-000000000001';
    const EVENT = '5d34e7f4-0000-4000-8000-000000000002';

    private static function node($type, $uuid, array $extra = array())
    {
        return array('type' => $type, 'uuid' => $uuid) + $extra;
    }

    private static function valueNode($value)
    {
        return array('type' => 'Value', 'value' => $value);
    }

    private static function uuid($i)
    {
        return sprintf('00000000-0000-4000-8000-%012d', $i);
    }

    private static function documentWithNodes($count)
    {
        $nodes = array();
        for ($i = 1; $i <= $count; $i++) {
            $nodes[] = self::node('Attribute', self::uuid($i));
        }
        return array('version' => 1, 'nodes' => $nodes);
    }

    private static function stored(array $nodes)
    {
        list($document) = AnalystGraphDocumentTool::normalise(array('nodes' => $nodes));
        return AnalystGraphDocumentTool::encode($document);
    }

    // ----------------------------------------------------------- validation

    public function testDocumentIsNormalised()
    {
        list($document, $errors) = AnalystGraphDocumentTool::normalise(array(
            'nodes' => array(
                self::node('Attribute', self::ATTRIBUTE, array('x' => 120, 'y' => -40.5, 'pinned' => true, 'label' => 'dropped')),
                array('type' => 'Value', 'value' => ' 8.8.8.8 ', 'uuid' => self::EVENT),
            ),
            'hidden_edges' => array('relationship:a', 'relationship:a'),
            'view' => array('layout' => 'force', 'zoom' => 1.5, 'center' => array(0, 0), 'theme' => 'dropped'),
            'extra' => 'dropped',
        ));

        $this->assertSame(array(), $errors);
        $this->assertSame(array(
            'version' => 1,
            'nodes' => array(
                array('type' => 'Attribute', 'uuid' => strtolower(self::ATTRIBUTE), 'x' => 120, 'y' => -40.5, 'pinned' => true),
                array('type' => 'Value', 'uuid' => Value::uuidFor('8.8.8.8'), 'value' => '8.8.8.8'),
            ),
            'hidden_edges' => array('relationship:a'),
            'view' => array('layout' => 'force', 'zoom' => 1.5, 'center' => array(0, 0)),
        ), $document);
    }

    public function testJsonStringIsAccepted()
    {
        list($document, $errors) = AnalystGraphDocumentTool::normalise('{"version":1,"nodes":[{"type":"Event","uuid":"' . self::EVENT . '"}]}');
        $this->assertSame(array(), $errors);
        $this->assertSame(self::EVENT, $document['nodes'][0]['uuid']);
    }

    public function testEmptyDocumentEncodesViewAsAnObject()
    {
        $this->assertSame(
            '{"version":1,"nodes":[],"hidden_edges":[],"view":{}}',
            AnalystGraphDocumentTool::encode(AnalystGraphDocumentTool::emptyDocument())
        );
    }

    /**
     * @dataProvider invalidDocuments
     */
    public function testInvalidDocumentIsRefused($content)
    {
        list($document, $errors) = AnalystGraphDocumentTool::normalise($content);
        $this->assertNull($document);
        $this->assertNotEmpty($errors);
    }

    public static function invalidDocuments()
    {
        return array(
            'not JSON' => array('{"nodes": ['),
            'not an object' => array(42),
            'unknown version' => array(array('version' => 2)),
            'nodes not a list' => array(array('nodes' => array('a' => self::node('Event', self::EVENT)))),
            'unknown node type' => array(array('nodes' => array(self::node('Tag', self::EVENT)))),
            'bad uuid' => array(array('nodes' => array(self::node('Event', 'not-a-uuid')))),
            'duplicate node' => array(array('nodes' => array(self::node('Event', self::EVENT), self::node('Event', strtoupper(self::EVENT))))),
            'duplicate value' => array(array('nodes' => array(self::valueNode('8.8.8.8'), self::valueNode(' 8.8.8.8')))),
            'empty value' => array(array('nodes' => array(self::valueNode('  ')))),
            'oversized value' => array(array('nodes' => array(self::valueNode(str_repeat('a', 1025))))),
            'non-numeric position' => array(array('nodes' => array(self::node('Event', self::EVENT, array('x' => '12'))))),
            'hidden edge not a string' => array(array('hidden_edges' => array(array('id' => 1)))),
            'view as a list' => array(array('view' => array(1, 2))),
            'bad zoom' => array(array('view' => array('zoom' => 0))),
        );
    }

    public function testSameUuidUnderTwoTypesIsNotADuplicate()
    {
        list(, $errors) = AnalystGraphDocumentTool::normalise(array(
            'nodes' => array(self::node('Event', self::EVENT), self::node('Attribute', self::EVENT)),
        ));
        $this->assertSame(array(), $errors);
    }

    public function testValueAtTheByteCapIsAccepted()
    {
        list(, $errors) = AnalystGraphDocumentTool::normalise(array('nodes' => array(self::valueNode(str_repeat('a', 1024)))));
        $this->assertSame(array(), $errors);
    }

    public function testErrorsNameTheNode()
    {
        list(, $errors) = AnalystGraphDocumentTool::normalise(array(
            'nodes' => array(self::node('Event', self::EVENT), self::node('Tag', self::EVENT)),
        ));
        $this->assertSame(array('nodes[1]: unknown node type.'), $errors);
    }

    // ----------------------------------------------------------------- caps

    public function testNodeCap()
    {
        list(, $errors) = AnalystGraphDocumentTool::normalise(self::documentWithNodes(2000));
        $this->assertSame(array(), $errors);

        list($document, $errors) = AnalystGraphDocumentTool::normalise(self::documentWithNodes(2001));
        $this->assertNull($document);
        $this->assertSame(array('A graph holds at most 2000 nodes.'), $errors);
    }

    public function testByteCap()
    {
        $edges = array();
        for ($i = 0; $i < 66000; $i++) {
            $edges[] = str_pad('relationship:' . $i, 255, 'x');
        }
        list($document, $errors) = AnalystGraphDocumentTool::normalise(array('hidden_edges' => $edges));
        $this->assertNull($document);
        $this->assertSame(array('A graph document is at most 16777216 bytes.'), $errors);
    }

    public function testMeasure()
    {
        $content = self::stored(array(self::node('Event', self::EVENT), self::valueNode('8.8.8.8')));
        $this->assertSame(
            array('content_size' => strlen($content), 'node_count' => 2),
            AnalystGraphDocumentTool::measure($content)
        );
    }

    // ---------------------------------------------------------- model glue

    public function testModelStoresTheNormalisedDocument()
    {
        $graph = new GraphTestGraph();
        $graph->data = array('Graph' => array('content' => array('nodes' => array(self::node('Attribute', self::ATTRIBUTE)))));

        $this->assertTrue($graph->validDocument(array('content' => $graph->data['Graph']['content'])));
        $this->assertSame(
            '{"version":1,"nodes":[{"type":"Attribute","uuid":"' . strtolower(self::ATTRIBUTE) . '"}],"hidden_edges":[],"view":{}}',
            $graph->data['Graph']['content']
        );
    }

    public function testModelReportsDocumentErrors()
    {
        $graph = new GraphTestGraph();
        $result = $graph->validDocument(array('content' => array('nodes' => array(self::node('Tag', self::EVENT)))));
        $this->assertSame('nodes[0]: unknown node type.', $result);
    }

    public function testGraphIsAnAnalystDataTypeButNotEmbedded()
    {
        $this->assertContains('Graph', AnalystData::TYPES);
        $this->assertNotContains('Graph', AnalystData::ANALYST_DATA_TYPES);
        $this->assertContains('Graph', AnalystData::valid_targets);
        $this->assertContains('Value', AnalystData::valid_targets);
        $this->assertSame(AnalystData::GRAPH, (new GraphTestGraph())->current_type_id);
    }

    public function testGraphsHaveNoLanguage()
    {
        $this->assertNotContains('language', (new GraphTestGraph())->getEditableFields());
    }

    public function testDistributionIsRequiredOnCreateOnly()
    {
        $this->assertSame('create', (new GraphTestGraph())->validate['distribution']['required']);
    }

    // ---------------------------------------------------------- edit rights

    private static function user($orgUuid, $siteAdmin = false, $permAnalystData = true)
    {
        return array(
            'Role' => array('perm_site_admin' => $siteAdmin, 'perm_analyst_data' => $permAnalystData),
            'Organisation' => array('uuid' => $orgUuid),
        );
    }

    /**
     * @dataProvider editRights
     */
    public function testEditRights(array $user, $expected)
    {
        $graph = new GraphTestGraph();
        $record = array('Graph' => array('orgc_uuid' => self::uuid(1)));
        $this->assertSame($expected, $graph->canEditAnalystData($user, $record, 'Graph'));
    }

    public static function editRights()
    {
        return array(
            'creator org' => array(self::user(self::uuid(1)), true),
            'creator org without analyst-data permission' => array(self::user(self::uuid(1), false, false), false),
            'other org' => array(self::user(self::uuid(2)), false),
            'site admin of another org' => array(self::user(self::uuid(2), true, false), true),
        );
    }

    // -------------------------------------------------------- audit summary

    public function testAuditSummaryOfAnEdit()
    {
        $old = self::stored(array(self::node('Event', self::EVENT), self::node('Attribute', self::uuid(1))));
        $new = self::stored(array(self::node('Event', self::EVENT), self::node('Attribute', self::uuid(2)), self::valueNode('8.8.8.8')));

        $this->assertSame(array(
            'node_count' => array(2, 3),
            'content_size' => array(strlen($old), strlen($new)),
            'nodes_added_count' => 2,
            'nodes_added' => array('Attribute:' . self::uuid(2), 'Value:' . Value::uuidFor('8.8.8.8')),
            'nodes_removed_count' => 1,
            'nodes_removed' => array('Attribute:' . self::uuid(1)),
        ), AnalystGraphDocumentTool::summariseChange($old, $new));
    }

    public function testAuditSummaryWithoutAPreviousDocument()
    {
        $content = self::stored(array(self::node('Event', self::EVENT)));
        $this->assertSame(
            array('node_count' => 1, 'content_size' => strlen($content)),
            AnalystGraphDocumentTool::summariseChange(null, $content)
        );
    }

    public function testAuditSummaryListsAreCapped()
    {
        $new = AnalystGraphDocumentTool::encode(self::documentWithNodes(150) + array('hidden_edges' => array(), 'view' => array()));
        $summary = AnalystGraphDocumentTool::summariseChange(self::stored(array()), $new);
        $this->assertSame(150, $summary['nodes_added_count']);
        $this->assertCount(100, $summary['nodes_added']);
    }

    public function testModelSummarisesOnlyTheContentAndAddsTheRevision()
    {
        $graph = new GraphTestGraph();
        $graph->data = array('Graph' => array('revision' => 4));
        $content = self::stored(array(self::node('Event', self::EVENT)));

        $this->assertNull($graph->auditLogSummary('name', 'old', 'new'));
        $this->assertSame(
            array('revision' => 4, 'node_count' => 1, 'content_size' => strlen($content)),
            $graph->auditLogSummary('content', null, $content)
        );
    }

    public function testAuditLogRecordsTheSummaryInPlaceOfTheDocument()
    {
        Configure::write('MISP.log_new_audit', true);
        $recorder = new GraphTestRecorder();
        ClassRegistry::$instances['AuditLog'] = $recorder;

        $old = self::stored(array(self::node('Event', self::EVENT)));
        $new = self::stored(array(self::node('Event', self::EVENT), self::valueNode('8.8.8.8')));
        $model = new GraphTestModel();
        $model->data = array('Graph' => array('id' => 1, 'name' => 'Campaign', 'content' => $new, 'revision' => 3));

        $behavior = new AuditLogBehavior();
        $behavior->setup($model);
        $beforeSave = new ReflectionProperty(AuditLogBehavior::class, 'beforeSave');
        $beforeSave->setAccessible(true);
        $beforeSave->setValue($behavior, array('Graph' => array('id' => 1, 'name' => 'Campaign', 'content' => $old, 'revision' => 2)));

        $behavior->afterSave($model, false, array('fieldList' => array()));

        $this->assertCount(1, $recorder->calls);
        $row = $recorder->calls[0];
        $this->assertSame('Graph', $row['model']);
        $this->assertSame('Campaign', $row['model_title']);
        $this->assertSame(array('content'), array_keys($row['change']));
        $this->assertSame(3, $row['change']['content']['revision']);
        $this->assertSame(array(1, 2), $row['change']['content']['node_count']);
        $this->assertSame(array('Value:' . Value::uuidFor('8.8.8.8')), $row['change']['content']['nodes_added']);
    }

    // ----------------------------------------------------------- embed skip

    private function registerEmbedFakes()
    {
        $fakes = array();
        foreach (array('Note', 'Opinion', 'Relationship', 'Graph', 'User') as $type) {
            $fakes[$type] = new GraphTestRecorder();
            ClassRegistry::$instances[$type] = $fakes[$type];
        }
        return $fakes;
    }

    public function testBulkFetchNeverLoadsGraphs()
    {
        $fakes = $this->registerEmbedFakes();
        $behavior = new AnalystDataParentBehavior();

        $behavior->fetchAnalystDataBulk(new GraphTestModel(), array(self::EVENT), array('Note', 'Graph'));

        $this->assertCount(1, $fakes['Note']->calls);
        $this->assertSame(array(), $fakes['Graph']->calls);
    }

    public function testBulkAttachNeverLoadsGraphs()
    {
        $fakes = $this->registerEmbedFakes();
        $behavior = new AnalystDataParentBehavior();

        $behavior->attachAnalystDataBulk(new GraphTestModel(), array(array('uuid' => self::EVENT)), array('Graph', 'Opinion'));

        $this->assertCount(1, $fakes['Opinion']->calls);
        $this->assertSame(array(), $fakes['Graph']->calls);
    }

    // ------------------------------------------------------------ API output

    public function testDecodeKeepsAnEmptyViewAnObject()
    {
        $decoded = AnalystGraphDocumentTool::decode('{"version":1,"nodes":[],"hidden_edges":[],"view":[]}');

        $this->assertStringContainsString('"view":{}', json_encode($decoded));
        $this->assertNull(AnalystGraphDocumentTool::decode('not json'));
    }

    // ------------------------------------------------------- locked write

    private function writableGraph($storedRevision)
    {
        $graph = new GraphTestWritableGraph();
        $graph->db = new GraphTestDataSource();
        if ($storedRevision !== null) {
            $graph->db->rows = array(array('analyst_graphs' => array(
                'revision' => (string)$storedRevision,
                'content' => self::stored(array(self::node('Event', self::EVENT))),
            )));
        }
        return $graph;
    }

    public function testWriteLocksTheRowBeforeReadingTheRevision()
    {
        $graph = $this->writableGraph(5);

        $graph->writeContent(7, function (array $stored) {
            return $stored;
        }, 5);

        $this->assertSame('begin', $graph->db->log[0]);
        list($sql, $params, $options) = $graph->db->log[1];
        $this->assertStringEndsWith('FOR UPDATE', $sql);
        $this->assertSame(array(7), $params);
        $this->assertSame(array('cache' => false), $options);
    }

    public function testStaleWriteIsAConflictAndSavesNothing()
    {
        $graph = $this->writableGraph(5);
        $called = false;

        $result = $graph->writeContent(7, function () use (&$called) {
            $called = true;
            return array();
        }, 4);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame(5, $result['revision']);
        $this->assertFalse($called);
        $this->assertSame(array(), $graph->saves);
        $this->assertSame('rollback', end($graph->db->log));
    }

    public function testCurrentWriteSavesTheChangedDocumentAndCommits()
    {
        $graph = $this->writableGraph(5);
        $seen = null;

        $result = $graph->writeContent(7, function (array $stored) use (&$seen) {
            $seen = $stored;
            $stored['nodes'][] = array('type' => 'Value', 'value' => '8.8.8.8');
            return $stored;
        }, '5');

        $this->assertSame('Event', $seen['nodes'][0]['type']);
        $this->assertSame('saved', $result['status']);
        $this->assertSame(6, $result['revision']);
        $this->assertSame(1, $result['node_count']);
        list($data, $options) = $graph->saves[0];
        $this->assertSame(7, $data['Graph']['id']);
        $this->assertCount(2, $data['Graph']['content']['nodes']);
        $this->assertSame(array('fieldList' => array('content', 'modified')), $options);
        $this->assertSame(array('save', 'commit'), array_slice($graph->db->log, -2));
    }

    public function testWriteWithoutABaseRevisionAppliesToTheStoredOne()
    {
        $graph = $this->writableGraph(5);

        $result = $graph->writeContent(7, function (array $stored) {
            return $stored;
        });

        $this->assertSame('saved', $result['status']);
    }

    public function testInvalidWriteRollsBack()
    {
        $graph = $this->writableGraph(5);
        $graph->refuse = true;

        $result = $graph->writeContent(7, function () {
            return array('nodes' => array(array('type' => 'Tag')));
        }, 5);

        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('content', $result['errors']);
        $this->assertSame('rollback', end($graph->db->log));
        $this->assertNotContains('commit', $graph->db->log);
    }

    public function testWriteThatChangesNothingSavesNothing()
    {
        $graph = $this->writableGraph(5);

        $result = $graph->writeContent(7, function () {
            return null;
        });

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(5, $result['revision']);
        $this->assertSame(1, $result['node_count']);
        $this->assertSame(array(), $graph->saves);
        $this->assertSame('commit', end($graph->db->log));
    }

    // ------------------------------------------------- add / remove nodes

    public function testAddNodesAppendsAndReportsEachItem()
    {
        $document = json_decode(self::stored(array(self::node('Event', self::EVENT))), true);

        list($document, $report) = AnalystGraphDocumentTool::addNodes($document, array(
            self::node('Event', self::EVENT),
            self::node('Attribute', self::ATTRIBUTE),
            self::valueNode(' 8.8.8.8 '),
            self::valueNode('8.8.8.8'),
            array('type' => 'Tag', 'uuid' => self::EVENT),
            self::node('Attribute', strtolower(self::ATTRIBUTE)),
        ));

        $attribute = 'Attribute:' . strtolower(self::ATTRIBUTE);
        $value = 'Value:' . Value::uuidFor('8.8.8.8');
        $this->assertSame(array($attribute, $value), $report['added']);
        $this->assertSame(array('Event:' . self::EVENT, $value, $attribute), $report['present']);
        $this->assertSame(array(array('index' => 4, 'error' => 'unknown node type.')), $report['refused']);
        $this->assertSame(array('Event:' . self::EVENT, $attribute, $value), array_map(array('AnalystGraphDocumentTool', 'nodeKey'), $document['nodes']));
        $this->assertSame('8.8.8.8', $document['nodes'][2]['value']);
    }

    public function testAddNodesStopsAtTheNodeCap()
    {
        $document = self::documentWithNodes(AnalystGraphDocumentTool::MAX_NODES - 1);

        list($document, $report) = AnalystGraphDocumentTool::addNodes($document, array(
            self::valueNode('a'),
            self::valueNode('b'),
        ));

        $this->assertCount(1, $report['added']);
        $this->assertSame(1, $report['refused'][0]['index']);
        $this->assertCount(AnalystGraphDocumentTool::MAX_NODES, $document['nodes']);
    }

    public function testRemoveNodesByNodeOrByKey()
    {
        $document = json_decode(self::stored(array(
            self::node('Event', self::EVENT),
            self::node('Attribute', self::ATTRIBUTE),
            self::valueNode('8.8.8.8'),
        )), true);

        list($document, $report) = AnalystGraphDocumentTool::removeNodes($document, array(
            'Attribute:' . self::ATTRIBUTE,
            self::valueNode(' 8.8.8.8 '),
            'Event:' . self::uuid(9),
            'nonsense',
            array('type' => 'Object'),
        ));

        $this->assertSame(array('Attribute:' . strtolower(self::ATTRIBUTE), 'Value:' . Value::uuidFor('8.8.8.8')), $report['removed']);
        $this->assertSame(array('Event:' . self::uuid(9)), $report['absent']);
        $this->assertSame(array(3, 4), array_column($report['refused'], 'index'));
        $this->assertSame(array('Event:' . self::EVENT), array_map(array('AnalystGraphDocumentTool', 'nodeKey'), $document['nodes']));
    }

    public function testWriteToAMissingGraph()
    {
        $graph = $this->writableGraph(null);

        $result = $graph->writeContent(7, function () {
            return array();
        }, 1);

        $this->assertSame('missing', $result['status']);
        $this->assertSame('rollback', end($graph->db->log));
    }

    // ------------------------------------------------------------ resolve

    const E1 = 'e1000000-0000-4000-8000-000000000001';
    const E2 = 'e2000000-0000-4000-8000-000000000002';
    const O1 = 'b1000000-0000-4000-8000-000000000021';
    const O2 = 'b2000000-0000-4000-8000-000000000022';
    const A1 = 'a1000000-0000-4000-8000-000000000031';
    const A_CHILD1 = 'ac100000-0000-4000-8000-000000000041';
    const A_CHILD2 = 'ac200000-0000-4000-8000-000000000042';
    const A3 = 'a3000000-0000-4000-8000-000000000033';
    const A_HIDDEN = 'aa000000-0000-4000-8000-000000000099';
    const A_UNDRAWN = 'ad000000-0000-4000-8000-000000000098';
    const C1 = 'c1000000-0000-4000-8000-000000000051';
    const C2 = 'c2000000-0000-4000-8000-000000000052';
    const C1_TAG = 'misp-galaxy:threat-actor="X"';

    private $registered = array();

    protected function tearDown(): void
    {
        foreach ($this->registered as $name) {
            unset(ClassRegistry::$instances[$name]);
        }
        $this->registered = array();
    }

    private function fake($name)
    {
        $fake = new GraphTestFake();
        ClassRegistry::$instances[$name] = $fake;
        $this->registered[] = $name;
        return $fake;
    }

    private static function inList($uuid, array $uuids)
    {
        return in_array(strtolower($uuid), array_map('strtolower', $uuids), true);
    }

    private function registerResolveFakes(array $readable)
    {
        $childAttributes = array(
            array('id' => 41, 'uuid' => self::A_CHILD1, 'object_id' => 21, 'event_id' => 11, 'type' => 'ip-dst', 'value' => '8.8.8.8', 'value1' => '8.8.8.8', 'value2' => '', 'Tag' => array()),
            array('id' => 42, 'uuid' => self::A_CHILD2, 'object_id' => 21, 'event_id' => 11, 'type' => 'domain', 'value' => 'example.org', 'value1' => 'example.org', 'value2' => '',
                'Tag' => array(array('name' => self::C1_TAG, 'relationship_type' => 'attributed-to'))),
        );
        $objects = array(
            strtoupper(self::O1) => array('id' => 21, 'uuid' => strtoupper(self::O1), 'event_id' => 11, 'template_uuid' => null, 'Attribute' => $childAttributes),
            self::O2 => array('id' => 22, 'uuid' => self::O2, 'event_id' => 11, 'template_uuid' => null, 'Attribute' => array()),
        );
        $attributes = array(
            array('id' => 31, 'uuid' => self::A1, 'object_id' => 0, 'event_id' => 11, 'type' => 'attachment', 'value' => 'a.png', 'Tag' => array()),
            $childAttributes[0],
            array('id' => 33, 'uuid' => self::A3, 'object_id' => 0, 'event_id' => 12, 'type' => 'ip-dst|port', 'value' => '1.2.3.4|80', 'value1' => '1.2.3.4', 'value2' => '80', 'Tag' => array()),
        );
        $this->fake('CollectionElement')->on('readableUuids', function ($user, $type, $uuids) use ($readable) {
            return array_values(array_filter($uuids, function ($uuid) use ($readable) {
                return self::inList($uuid, $readable);
            }));
        });
        $this->fake('MispObject')->on('fetchGraphObjects', function ($user, $conditions) use ($objects) {
            $found = array();
            foreach (array_reverse($objects, true) as $uuid => $object) {
                if (self::inList($uuid, $conditions['Object.uuid'])) {
                    $found[$uuid] = $object;
                }
            }
            return $found;
        });
        $this->fake('MispAttribute')->on('fetchGraphAttributes', function ($user, $conditions) use ($attributes) {
            return array_values(array_filter($attributes, function ($a) use ($conditions) {
                return self::inList($a['uuid'], $conditions['Attribute.uuid']);
            }));
        });
        $this->fake('GalaxyCluster')->on('fetchGalaxyClusters', function ($user, $options) {
            $rows = array();
            foreach (array(self::C1 => self::C1_TAG, self::C2 => 'misp-galaxy:tool="Y"') as $uuid => $tag) {
                if (self::inList($uuid, $options['conditions']['GalaxyCluster.uuid'])) {
                    $rows[] = array('GalaxyCluster' => array('uuid' => $uuid, 'tag_name' => $tag), 'Galaxy' => array('type' => 'threat-actor'));
                }
            }
            return $rows;
        });
        $this->fake('Event')
            ->on('find', function ($type, $query) {
                return self::inList(self::E1, $query['conditions']['Event.uuid']) ? array(self::E1 => '11') : array();
            })
            ->on('correlatedEventCards', function ($user, $ids) {
                $cards = array(
                    '11' => array('id' => '11', 'uuid' => self::E1, 'Galaxy' => array(array('type' => 'threat-actor', 'GalaxyCluster' => array(array('uuid' => self::C1, 'tag_name' => self::C1_TAG))))),
                    '12' => array('id' => '12', 'uuid' => self::E2, 'Galaxy' => array()),
                );
                return array_intersect_key($cards, array_flip($ids));
            });
        $this->fake('ObjectTemplate')->on('uiPrioritiesFor', function () {
            return array();
        });
        $this->fake('ObjectReference')->on('find', function () {
            return array(
                array('ObjectReference' => array('uuid' => 'r1', 'object_id' => 22, 'referenced_uuid' => strtoupper(self::O1), 'referenced_type' => '1', 'relationship_type' => 'co-worker')),
                array('ObjectReference' => array('uuid' => 'r2', 'object_id' => 21, 'referenced_uuid' => self::A1, 'referenced_type' => '0', 'relationship_type' => 'child')),
                array('ObjectReference' => array('uuid' => 'r3', 'object_id' => 22, 'referenced_uuid' => self::A_UNDRAWN, 'referenced_type' => '0', 'relationship_type' => 'child')),
            );
        });
        $this->fake('Relationship')
            ->on('buildConditions', function () {
                return array();
            })
            ->on('find', function () {
                $row = function ($uuid, $fromType, $from, $toType, $to) {
                    return array('Relationship' => array(
                        'uuid' => $uuid, 'object_type' => $fromType, 'object_uuid' => $from,
                        'related_object_type' => $toType, 'related_object_uuid' => $to,
                        'relationship_type' => 'related-to', 'authors' => 'a@b', 'orgc_uuid' => 'o', 'distribution' => '1',
                    ));
                };
                return array(
                    $row('rel1', 'Attribute', self::A1, 'Value', Value::uuidFor('8.8.8.8')),
                    $row('rel2', 'Attribute', self::A1, 'Event', self::E2),
                );
            });
        $this->fake('GalaxyClusterRelation')->on('fetchRelations', function () {
            return array(array('GalaxyClusterRelation' => array(
                'galaxy_cluster_uuid' => self::C1, 'referenced_galaxy_cluster_uuid' => self::C2, 'referenced_galaxy_cluster_type' => 'uses',
            )));
        });
    }

    private function resolveFixture()
    {
        $this->registerResolveFakes(array(self::E1, self::O1, self::O2, self::A1, self::A_CHILD1, self::A3, self::C1, self::C2));
        list($document) = AnalystGraphDocumentTool::normalise(array('nodes' => array(
            self::node('Event', self::E1),
            self::node('Object', self::O1),
            self::node('Object', self::O2),
            self::node('Attribute', self::A1),
            self::node('Attribute', self::A_HIDDEN),
            self::node('Attribute', self::A_CHILD1),
            self::node('Attribute', self::A3),
            self::node('GalaxyCluster', self::C1),
            self::node('GalaxyCluster', self::C2),
            self::valueNode(' 8.8.8.8 '),
            self::valueNode('1.2.3.4'),
            self::valueNode('EXAMPLE.ORG'),
        )));
        $data = new AnalystGraphData();
        return $data->resolve(array('id' => 1), $document);
    }

    public function testResolveLeavesOutWhatTheUserCannotRead()
    {
        $resolved = $this->resolveFixture();

        $keys = array_map(array('AnalystGraphDocumentTool', 'nodeKey'), $resolved['document']['nodes']);
        $this->assertNotContains('Attribute:' . self::A_HIDDEN, $keys);
        $this->assertCount(11, $keys);
        $this->assertSame(11, $resolved['meta']['nodes']);
        $this->assertSame(11, $resolved['meta']['drawn']);
        $this->assertSame(array(), $resolved['meta']['skipped']);
        $this->assertStringContainsString('"view":{}', json_encode($resolved['document']));
    }

    public function testResolvedRecordsFollowTheDocumentOrder()
    {
        $resolved = $this->resolveFixture();

        $this->assertSame(array(21, 22), array_column($resolved['Object'], 'id'));
        $this->assertSame(array(31, 41, 33), array_column($resolved['Attribute'], 'id'));
        $this->assertSame(array(self::C1, self::C2), array_column($resolved['GalaxyCluster'], 'uuid'));
        $this->assertSame(array('8.8.8.8', '1.2.3.4', 'EXAMPLE.ORG'), array_column($resolved['Value'], 'value'));
        $this->assertSame(base64_encode('8.8.8.8'), $resolved['Value'][0]['b64']);
        $this->assertSame(array(11, 12), array_keys($resolved['events']));
    }

    public function testResolvedEdges()
    {
        $resolved = $this->resolveFixture();

        $edges = array();
        foreach ($resolved['edges'] as $edge) {
            $edges[$edge['id']] = array($edge['kind'], $edge['from'], $edge['to']);
        }
        ksort($edges);
        $value = function ($v) {
            return Value::uuidFor($v);
        };
        $expected = array(
            'object-reference:r1' => array('object-reference', 'Object:' . self::O2, 'Object:' . self::O1),
            'object-reference:r2' => array('object-reference', 'Object:' . self::O1, 'Attribute:' . self::A1),
            'relationship:rel1' => array('relationship', 'Attribute:' . self::A1, 'Value:' . $value('8.8.8.8')),
            'contains:' . self::A_CHILD1 => array('contains', 'Object:' . self::O1, 'Attribute:' . self::A_CHILD1),
            'in-event:' . self::O1 => array('in-event', 'Object:' . self::O1, 'Event:' . self::E1),
            'in-event:' . self::O2 => array('in-event', 'Object:' . self::O2, 'Event:' . self::E1),
            'in-event:' . self::A1 => array('in-event', 'Attribute:' . self::A1, 'Event:' . self::E1),
            'tagged:' . self::E1 . '>' . self::C1 => array('tag', 'Event:' . self::E1, 'GalaxyCluster:' . self::C1),
            'tagged:' . self::A_CHILD2 . '>' . self::C1 => array('tag', 'Attribute:' . self::A_CHILD2, 'GalaxyCluster:' . self::C1),
            'cluster-relation:' . self::C1 . '>' . self::C2 . ':uses' => array('cluster-relation', 'GalaxyCluster:' . self::C1, 'GalaxyCluster:' . self::C2),
            'value:' . $value('8.8.8.8') . '>' . self::A_CHILD1 => array('value', 'Value:' . $value('8.8.8.8'), 'Attribute:' . self::A_CHILD1),
            'value:' . $value('1.2.3.4') . '>' . self::A3 => array('value', 'Value:' . $value('1.2.3.4'), 'Attribute:' . self::A3),
            'value:' . $value('EXAMPLE.ORG') . '>' . self::A_CHILD2 => array('value', 'Value:' . $value('EXAMPLE.ORG'), 'Attribute:' . self::A_CHILD2),
        );
        ksort($expected);
        $this->assertSame($expected, $edges);
    }

    public function testThumbnailFoldsAnObjectsAttributesAndKeepsTheLayout()
    {
        $this->registerResolveFakes(array(self::E1, self::O1, self::O2, self::A1, self::A_CHILD1, self::A3, self::C1, self::C2));
        list($document) = AnalystGraphDocumentTool::normalise(array(
            'nodes' => array(
                self::node('Event', self::E1) + array('x' => -320, 'y' => 0, 'pinned' => true),
                self::node('Object', self::O1) + array('x' => 12.345, 'y' => 40),
                self::node('Object', self::O2),
                self::node('Attribute', self::A1),
                self::node('Attribute', self::A_HIDDEN),
                self::node('Attribute', self::A_CHILD1),
                self::node('Attribute', self::A3),
                self::node('GalaxyCluster', self::C1),
                self::node('GalaxyCluster', self::C2),
                self::valueNode(' 8.8.8.8 '),
                self::valueNode('1.2.3.4'),
                self::valueNode('EXAMPLE.ORG'),
            ),
            'hidden_edges' => array('in-event:' . self::O2),
        ));

        $thumb = (new AnalystGraphData())->thumbnail(array('id' => 1), $document);

        $keys = array_column($thumb['nodes'], 'key');
        $this->assertNotContains('Attribute:' . self::A_HIDDEN, $keys, 'unreadable');
        $this->assertNotContains('Attribute:' . self::A_CHILD1, $keys, 'drawn as its object');
        $this->assertCount(10, $keys);
        $this->assertSame(11, $thumb['total'], 'every readable node is counted');
        $this->assertSame(array('key' => 'Event:' . self::E1, 'type' => 'Event', 'x' => -320.0, 'y' => 0.0, 'pinned' => true), $thumb['nodes'][0]);
        $this->assertSame(12.3, $thumb['nodes'][1]['x']);
        $this->assertArrayNotHasKey('x', $thumb['nodes'][2], 'no saved position, none given');

        $value = function ($v) {
            return 'Value:' . Value::uuidFor($v);
        };
        $pairs = array();
        foreach ($thumb['edges'] as list($from, $to, $kind)) {
            $pairs[] = $keys[$from] . ' ' . $keys[$to] . ' ' . $kind;
        }
        sort($pairs);
        $o1 = 'Object:' . self::O1;
        $expected = array(
            'Object:' . self::O2 . ' ' . $o1 . ' object-reference',
            $o1 . ' Attribute:' . self::A1 . ' object-reference',
            'Attribute:' . self::A1 . ' ' . $value('8.8.8.8') . ' relationship',
            $o1 . ' Event:' . self::E1 . ' in-event',
            'Attribute:' . self::A1 . ' Event:' . self::E1 . ' in-event',
            'Event:' . self::E1 . ' GalaxyCluster:' . self::C1 . ' tag',
            $o1 . ' GalaxyCluster:' . self::C1 . ' tag',
            'GalaxyCluster:' . self::C1 . ' GalaxyCluster:' . self::C2 . ' cluster-relation',
            $value('8.8.8.8') . ' ' . $o1 . ' value',
            $value('1.2.3.4') . ' Attribute:' . self::A3 . ' value',
            $value('EXAMPLE.ORG') . ' ' . $o1 . ' value',
        );
        sort($expected);
        $this->assertSame($expected, $pairs, 'no contains edge, the hidden one left out, child ends on their object');
    }

    public function testThumbnailDrawsItsBudget()
    {
        $this->registerResolveFakes(array());
        $nodes = array();
        for ($i = 0; $i <= AnalystGraphData::THUMBNAIL_BUDGET; $i++) {
            $nodes[] = self::valueNode('v' . $i);
        }
        list($document) = AnalystGraphDocumentTool::normalise(array('nodes' => $nodes));

        $thumb = (new AnalystGraphData())->thumbnail(array('id' => 1), $document);

        $this->assertCount(AnalystGraphData::THUMBNAIL_BUDGET, $thumb['nodes']);
        $this->assertSame(AnalystGraphData::THUMBNAIL_BUDGET + 1, $thumb['total']);
        $this->assertSame('Value:' . Value::uuidFor('v0'), $thumb['nodes'][0]['key'], 'in document order');
    }

    public function testResolveDrawsTheBudgetAndListsTheRest()
    {
        $this->registerResolveFakes(array());
        $nodes = array();
        for ($i = 0; $i <= AnalystGraphData::NODE_BUDGET; $i++) {
            $nodes[] = self::valueNode('v' . $i);
        }
        list($document) = AnalystGraphDocumentTool::normalise(array('nodes' => $nodes));
        $data = new AnalystGraphData();

        $resolved = $data->resolve(array('id' => 1), $document);

        $this->assertSame(AnalystGraphData::NODE_BUDGET + 1, $resolved['meta']['nodes']);
        $this->assertSame(AnalystGraphData::NODE_BUDGET, $resolved['meta']['drawn']);
        $this->assertSame(array('Value:' . Value::uuidFor('v' . AnalystGraphData::NODE_BUDGET)), $resolved['meta']['skipped']);
        $this->assertCount(AnalystGraphData::NODE_BUDGET, $resolved['Value']);
        $this->assertCount(AnalystGraphData::NODE_BUDGET + 1, $resolved['document']['nodes']);
    }

    // ------------------------------------------------------------ active graph

    const G1 = '9a000000-0000-4000-8000-000000000061';

    private static function analyst(array $role = array('perm_analyst_data' => 1))
    {
        return array(
            'id' => 3,
            'Role' => $role + array('perm_site_admin' => 0, 'perm_analyst_data' => 0),
            'Organisation' => array('uuid' => 'org-1'),
        );
    }

    private function storedActive($stored)
    {
        $this->fake('UserSetting')->on('getValueForUser', function ($userId, $setting) use ($stored) {
            return $setting === Graph::ACTIVE_SETTING ? $stored : null;
        });
    }

    private function settingWriter($loggedBefore, $fail = false)
    {
        $behaviors = new GraphTestBehaviors($loggedBefore);
        $setting = new GraphTestUserSetting();
        $setting->Behaviors = $behaviors;
        $setting->on('setSettingInternal', function ($userId, $name, $value) use ($behaviors, $fail) {
            $behaviors->log[] = array('save', $userId, $name, $value);
            if ($fail) {
                throw new RuntimeException('database gone');
            }
            return array('UserSetting' => array('id' => 1));
        });
        ClassRegistry::$instances['UserSetting'] = $setting;
        $this->registered[] = 'UserSetting';
        return $behaviors;
    }

    public function unusableStoredValues()
    {
        return array(
            'never set' => array(null),
            'empty' => array(array()),
            'cleared' => array(array('graph_uuid' => null)),
            'not a uuid' => array(array('graph_uuid' => 'zz')),
            'not a string' => array(array('graph_uuid' => 5)),
            'not an object' => array('9a000000-0000-4000-8000-000000000061'),
        );
    }

    /**
     * @dataProvider unusableStoredValues
     */
    public function testNoActiveGraphWithoutAUsableStoredUuid($stored)
    {
        $this->storedActive($stored);
        $graph = new GraphTestActiveGraph();
        $this->assertNull($graph->activeFor(self::analyst()));
        $this->assertSame(array(), $graph->summaryCalls);
    }

    public function testActiveGraphIsLookedUpByItsLowercaseUuid()
    {
        $this->storedActive(array('graph_uuid' => strtoupper(self::G1)));
        $graph = new GraphTestActiveGraph();
        $graph->summaryRows = array(array('uuid' => self::G1, '_canEdit' => true));

        $this->assertSame(self::G1, $graph->activeFor(self::analyst(), false)['uuid']);
        $this->assertSame(array(array(array('Graph.uuid' => self::G1), array('targets' => false))), $graph->summaryCalls);
    }

    public function testAnActiveGraphTheUserCanNoLongerEditIsNone()
    {
        $this->storedActive(array('graph_uuid' => self::G1));
        $graph = new GraphTestActiveGraph();
        $graph->summaryRows = array(array('uuid' => self::G1, '_canEdit' => false));
        $this->assertNull($graph->activeFor(self::analyst()));

        $graph->summaryRows = array();
        $this->assertNull($graph->activeFor(self::analyst()));
    }

    public function testStoringTheActiveGraphKeepsItOutOfTheLegacyLog()
    {
        $behaviors = $this->settingWriter(true);
        $this->assertTrue((new GraphTestActiveGraph())->storeActive(self::analyst(), self::G1));
        $this->assertSame(array(
            array('disable', 'SysLogLogable.SysLogLogable'),
            array('save', 3, Graph::ACTIVE_SETTING, array('graph_uuid' => self::G1)),
            array('enable', 'SysLogLogable.SysLogLogable'),
        ), $behaviors->log);
    }

    public function testClearingStoresNoGraphAndALogAlreadyOffStaysOff()
    {
        $behaviors = $this->settingWriter(false);
        $this->assertTrue((new GraphTestActiveGraph())->storeActive(self::analyst(), null));
        $this->assertSame(array(
            array('disable', 'SysLogLogable.SysLogLogable'),
            array('save', 3, Graph::ACTIVE_SETTING, array('graph_uuid' => null)),
        ), $behaviors->log);
    }

    public function testTheLegacyLogComesBackAfterAFailedWrite()
    {
        $behaviors = $this->settingWriter(true, true);
        try {
            (new GraphTestActiveGraph())->storeActive(self::analyst(), self::G1);
            $this->fail('The write failure was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('database gone', $e->getMessage());
        }
        $this->assertSame(array('enable', 'SysLogLogable.SysLogLogable'), end($behaviors->log));
    }

    public function testOnlyAnalystsAndSiteAdminsHaveEditableGraphs()
    {
        $graph = new GraphTestActiveGraph();
        $this->assertSame(array(), $graph->editableBy(self::analyst(array())));
        $this->assertSame(array(), $graph->summaryCalls);

        $graph->editableBy(self::analyst(array('perm_site_admin' => 1)));
        $this->assertCount(1, $graph->summaryCalls);
    }

    public function testEditableGraphsAreTheOrganisationsOwnThatTheUserCanEdit()
    {
        $graph = new GraphTestActiveGraph();
        $graph->summaryRows = array(
            array('uuid' => 'a', '_canEdit' => true),
            array('uuid' => 'b', '_canEdit' => false),
            array('uuid' => 'c', '_canEdit' => true),
        );

        $editable = $graph->editableBy(self::analyst());

        $this->assertSame(array('a', 'c'), array_column($editable, 'uuid'));
        $this->assertSame(array(array(
            array('Graph.orgc_uuid' => 'org-1'),
            array('limit' => Graph::EDITABLE_LIMIT),
        )), $graph->summaryCalls);
    }

    public function testTargetsAreLabelledOnlyWhereTheUserCanReadThem()
    {
        $collection = 'c0000000-0000-4000-8000-000000000071';
        $hiddenEvent = 'e9000000-0000-4000-8000-000000000079';
        $this->fake('Event')->on('fetchSimpleEvents', function ($user, $params) {
            $this->assertSame(array(self::E1, 'e9000000-0000-4000-8000-000000000079'), $params['conditions']['Event.uuid']);
            return array(array('Event' => array('id' => '11', 'uuid' => strtoupper(self::E1), 'info' => 'Phishing wave')));
        });
        $this->fake('GalaxyCluster')->on('fetchGalaxyClusters', function ($user, $options) {
            return array(array('GalaxyCluster' => array('id' => '51', 'uuid' => self::C1, 'value' => 'APT29')));
        });
        $this->fake('Collection')
            ->on('buildConditions', function ($userId) {
                return array('Collection.org_id' => 1);
            })
            ->on('find', function ($type, $query) use ($collection) {
                $this->assertSame(array('Collection.org_id' => 1), $query['conditions']['AND'][1]);
                return array(array('Collection' => array('id' => '7', 'uuid' => $collection, 'name' => 'Campaign')));
            });
        $graphs = array(
            array('uuid' => 'g1', 'object_type' => 'Event', 'object_uuid' => self::E1),
            array('uuid' => 'g2', 'object_type' => 'Event', 'object_uuid' => strtoupper($hiddenEvent)),
            array('uuid' => 'g3', 'object_type' => 'GalaxyCluster', 'object_uuid' => self::C1),
            array('uuid' => 'g4', 'object_type' => 'Collection', 'object_uuid' => $collection),
            array('uuid' => 'g5', 'object_type' => 'Event', 'object_uuid' => self::E1),
        );

        $labelled = (new AnalystGraphData())->labelTargets(array('id' => 3), $graphs);

        $this->assertSame(array('type' => 'Event', 'uuid' => self::E1, 'id' => 11, 'label' => 'Phishing wave'), $labelled[0]['target']);
        $this->assertSame(array('type' => 'Event', 'uuid' => $hiddenEvent, 'id' => null, 'label' => null), $labelled[1]['target']);
        $this->assertSame(array('type' => 'GalaxyCluster', 'uuid' => self::C1, 'id' => 51, 'label' => 'APT29'), $labelled[2]['target']);
        $this->assertSame(array('type' => 'Collection', 'uuid' => $collection, 'id' => 7, 'label' => 'Campaign'), $labelled[3]['target']);
        $this->assertSame($labelled[0]['target'], $labelled[4]['target']);
    }

    // --------------------------------------------------------- viewer counts

    public function testVisibleCountsAskOncePerTypeForEveryGraph()
    {
        $asked = array();
        $this->fake('CollectionElement')->on('readableUuids', function ($user, $type, $uuids) use (&$asked) {
            $asked[] = array($type, $uuids);
            return array_values(array_diff($uuids, array(self::A_HIDDEN)));
        });

        $counts = (new AnalystGraphData())->visibleCounts(array('id' => 3), array(
            7 => self::stored(array(self::node('Attribute', self::A1), self::node('Attribute', self::A_HIDDEN), self::valueNode('8.8.8.8'))),
            8 => json_decode(self::stored(array(self::node('Attribute', self::A1), self::node('Event', self::E1))), true),
            9 => 'not json',
        ));

        $this->assertSame(array(7 => 2, 8 => 2, 9 => 0), $counts);
        $this->assertSame(array(
            array('Attribute', array(self::A1, self::A_HIDDEN)),
            array('Event', array(self::E1)),
        ), $asked);
    }

    public function testSummariesCountOnlyWhatTheUserMayRead()
    {
        $this->fake('CollectionElement')->on('readableUuids', function ($user, $type, $uuids) {
            return array_values(array_diff($uuids, array(self::A_HIDDEN)));
        });
        ClassRegistry::$instances['AnalystGraphData'] = new AnalystGraphData();
        $this->registered[] = 'AnalystGraphData';
        $graph = new GraphTestCountedGraph();
        $graph->contents = array(
            1 => self::stored(array(self::node('Attribute', self::A1), self::node('Attribute', self::A_HIDDEN))),
            2 => self::stored(array()),
        );

        $out = $graph->withVisibleCounts(self::analyst(), array(
            array('id' => 1, 'node_count' => 2),
            array('id' => 2, 'node_count' => 0),
        ));

        $this->assertSame(array(1, 0), array_column($out, 'node_count'));
        $this->assertSame(array(array(1, 2)), $graph->asked);
        $this->assertSame(array(), $graph->withVisibleCounts(self::analyst(), array()));
        $this->assertCount(1, $graph->asked);
    }
}
