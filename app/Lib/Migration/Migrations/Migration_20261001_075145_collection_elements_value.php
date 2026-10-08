<?php

App::uses('AbstractMigration', 'Migration');

/**
 * Let a collection element hold a literal value
 */
class Migration_20261001_075145_collection_elements_value extends AbstractMigration
{
    public $description = 'Let a collection element hold a literal value';

    /**
     * @var bool
     */
    public $requiresLogout = false;

    /**
     * collection_elements.value carries the literal of a 'Value' element,
     * which points at no row of its own. Every other element type leaves it
     * null, so there is nothing to backfill.
     *
     * Interim: once values get a table of their own, a Value element points
     * at that record like every other type, and a later migration moves these
     * literals there and drops this column.
     *
     * @param SchemaBuilder $schema
     * @return void
     */
    public function up(SchemaBuilder $schema)
    {
        if ($this->inspector()->hasColumn('collection_elements', 'value')) {
            return;
        }
        $schema->table('collection_elements')->addColumn('value', 'text', array(
            'null' => true,
            'default' => null,
            'after' => 'element_type',
        ));
    }
}
