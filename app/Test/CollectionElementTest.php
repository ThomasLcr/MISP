<?php
/**
 * CollectionElement: element types, Value elements and readability.
 *
 * Pure PHPUnit, no CakePHP bootstrap and no database, as the other tests
 * under app/Test/. The framework classes the model touches are stubbed below,
 * each guarded so that a full-suite run sharing one process keeps whichever
 * stub loaded first.
 */

require_once __DIR__ . '/../Vendor/autoload.php';

if (!class_exists('App', false)) {
    class App
    {
        public static function uses($class, $package)
        {
        }
    }
}

if (!class_exists('CakeText', false)) {
    class CakeText
    {
        private static $counter = 0;

        public static function uuid()
        {
            self::$counter++;
            return sprintf('00000000-0000-4000-8000-%012d', self::$counter);
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
                self::$instances[$name] = new CollectionElementTestFakeModel();
            }
            return self::$instances[$name];
        }
    }
}

if (!class_exists('CollectionElementTestFakeModel', false)) {
    class CollectionElementTestFakeModel
    {
        public $alias = 'Fake';

        public function find($type, $opts = array())
        {
            return array();
        }
    }
}

if (!class_exists('Hash', false)) {
    class Hash
    {
        /** Only the '{n}.Model.field' shape the model uses. */
        public static function extract(array $data, $path)
        {
            list(, $model, $field) = explode('.', $path);
            $out = array();
            foreach ($data as $row) {
                if (isset($row[$model][$field])) {
                    $out[] = $row[$model][$field];
                }
            }
            return $out;
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

require_once __DIR__ . '/../Model/Value.php';
require_once __DIR__ . '/../Model/CollectionElement.php';

use PHPUnit\Framework\TestCase;

class TestableCollectionElement extends CollectionElement
{
    public function __construct()
    {
    }

    public function invalidate($field, $value = true)
    {
        $this->validationErrors[$field][] = $value;
    }

    public function validateData(array $element)
    {
        $this->id = false;
        $this->data = array('CollectionElement' => $element);
        $this->validationErrors = array();
        $this->beforeValidate();
        return $this->data['CollectionElement'];
    }
}

/** Records the options of the one fetch it answers. */
class RecordingFetcher
{
    public $alias;
    public $calls = array();
    private $rows;

    public function __construct($alias, array $rows)
    {
        $this->alias = $alias;
        $this->rows = $rows;
    }

    public function fetchAttributesSimple(array $user, array $options = array())
    {
        $this->calls[] = $options;
        return $this->rows;
    }

    public function fetchObjectSimple(array $user, $options = array())
    {
        $this->calls[] = $options;
        return $this->rows;
    }
}

class CollectionElementTest extends TestCase
{
    /** @var TestableCollectionElement */
    private $element;

    protected function setUp(): void
    {
        $this->element = new TestableCollectionElement();
    }

    /**
     * Fixed forever: these are what every instance calls these values, so a
     * change here breaks dedup against data already stored and synced.
     * Computed independently with Python's uuid.uuid5.
     */
    public function testValueUuidIsStable()
    {
        $this->assertSame('57668568-d717-5a92-91bf-be3a939ee8eb', Value::uuidFor('8.8.8.8'));
        $this->assertSame('024b2526-3080-5e9a-940a-d121967b93d8', Value::uuidFor('évil.example'));
    }

    public function testValueUuidIsVersion5AndIgnoresSurroundingWhitespace()
    {
        $uuid = Value::uuidFor("  8.8.8.8\n");
        $this->assertSame(Value::uuidFor('8.8.8.8'), $uuid);
        $this->assertRegExp('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
    }

    public function testValueUuidKeepsCase()
    {
        $this->assertNotSame(Value::uuidFor('Example.com'), Value::uuidFor('example.com'));
    }

    public function testValueElementDerivesItsUuidAndIgnoresAPayloadOne()
    {
        $saved = $this->element->validateData(array(
            'element_type' => 'Value',
            'value' => ' 8.8.8.8 ',
            'element_uuid' => '11111111-1111-4111-8111-111111111111',
        ));
        $this->assertSame('8.8.8.8', $saved['value']);
        $this->assertSame(Value::uuidFor('8.8.8.8'), $saved['element_uuid']);
        $this->assertEmpty($this->element->validationErrors);
    }

    public function testEmptyValueIsRefused()
    {
        foreach (array('', '   ', null, array('x')) as $value) {
            $this->element->validateData(array('element_type' => 'Value', 'value' => $value));
            $this->assertArrayHasKey('value', $this->element->validationErrors);
        }
    }

    public function testOversizedValueIsRefused()
    {
        $this->element->validateData(array(
            'element_type' => 'Value',
            'value' => str_repeat('a', CollectionElement::VALUE_MAX_BYTES),
        ));
        $this->assertEmpty($this->element->validationErrors);
        $this->element->validateData(array(
            'element_type' => 'Value',
            'value' => str_repeat('a', CollectionElement::VALUE_MAX_BYTES + 1),
        ));
        $this->assertArrayHasKey('value', $this->element->validationErrors);
    }

    public function testPointerElementsCarryNoValue()
    {
        $uuid = '5d34e7f4-bc50-4907-b4e3-245ea1501b3a';
        $saved = $this->element->validateData(array(
            'element_type' => 'Attribute',
            'element_uuid' => $uuid,
            'value' => 'smuggled',
        ));
        $this->assertNull($saved['value']);
        $this->assertSame($uuid, $saved['element_uuid']);
    }

    public function testElementTypes()
    {
        foreach (array('Event', 'GalaxyCluster', 'Attribute', 'Object', 'Value') as $type) {
            $this->assertTrue($this->element->validElementType(array('element_type' => $type)), $type);
        }
        foreach (array('Tag', 'attribute', 'MispAttribute', '') as $type) {
            $this->assertFalse($this->element->validElementType(array('element_type' => $type)), $type);
        }
    }

    public function testValuesAreReadableToWhoeverSeesTheCollection()
    {
        $uuids = array(Value::uuidFor('8.8.8.8'), Value::uuidFor('1.1.1.1'));
        $this->assertSame($uuids, $this->element->readableUuids(array(), 'Value', $uuids));
    }

    public function testAttributeReadabilityGoesThroughTheAclFetch()
    {
        $visible = '5d34e7f4-bc50-4907-b4e3-245ea1501b3a';
        $hidden = '4da00e7f-d4fe-4c05-b971-d25233f92c86';
        $fetcher = new RecordingFetcher('Attribute', array(
            array('Attribute' => array('uuid' => $visible)),
        ));
        ClassRegistry::$instances['MispAttribute'] = $fetcher;
        $user = array('id' => 2, 'Role' => array('perm_site_admin' => 0));

        $readable = $this->element->readableUuids($user, 'Attribute', array($visible, $hidden, $visible));

        $this->assertSame(array($visible), $readable);
        $this->assertCount(1, $fetcher->calls);
        $this->assertSame(array($visible, $hidden), $fetcher->calls[0]['conditions']['Attribute.uuid']);
        $this->assertSame(0, $fetcher->calls[0]['conditions']['Attribute.deleted']);
    }

    public function testObjectReadabilityGoesThroughTheAclFetch()
    {
        $fetcher = new RecordingFetcher('Object', array());
        ClassRegistry::$instances['MispObject'] = $fetcher;

        $readable = $this->element->readableUuids(array(), 'Object', array('735c9ac8-7ff9-4a30-91d2-79be2ea1ebc0'));

        $this->assertSame(array(), $readable);
        $this->assertSame(0, $fetcher->calls[0]['conditions']['Object.deleted']);
    }

    public function testUnknownTypeIsNeverReadable()
    {
        $this->assertSame(array(), $this->element->readableUuids(array(), 'Tag', array('5d34e7f4-bc50-4907-b4e3-245ea1501b3a')));
    }
}
