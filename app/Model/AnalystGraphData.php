<?php
App::uses('AppModel', 'Model');
App::uses('AnalystGraphDocumentTool', 'Tools');
App::uses('ValueUrlTool', 'Tools/ValueProfile');

/**
 * An analyst graph as one user sees it: the nodes they may read, their
 * records shaped as the Pivot Explorer's builders read them, and the edges
 * MISP holds between them. A node the user cannot read is left out without
 * a trace.
 */
class AnalystGraphData extends AppModel
{
    public $useTable = false;

    /** Nodes drawn at once: Pivotick's detail threshold. */
    const NODE_BUDGET = 1500;

    /** Nodes a thumbnail draws. */
    const THUMBNAIL_BUDGET = 300;

    private $models = [];

    private function model($alias)
    {
        if (!isset($this->models[$alias])) {
            $this->models[$alias] = ClassRegistry::init($alias);
        }
        return $this->models[$alias];
    }

    /**
     * @param array $user
     * @param array $document A stored graph document, decoded
     * @param int $budget The visible nodes, in document order, that get records
     * @return array
     */
    public function resolve(array $user, array $document, $budget = self::NODE_BUDGET)
    {
        $visible = $this->visibleNodes($user, $document['nodes'] ?? []);
        $drawn = array_slice($visible, 0, $budget);
        $skipped = array_slice($visible, $budget);
        $uuids = $this->uuidsByType($drawn);

        $objects = $this->objects($user, $uuids['Object']);
        $attributes = $this->attributes($user, $uuids['Attribute']);
        $clusters = $this->clusters($user, $uuids['GalaxyCluster']);
        $values = [];
        foreach ($drawn as $node) {
            if ($node['type'] === 'Value') {
                $values[] = [
                    'uuid' => $node['uuid'],
                    'value' => $node['value'],
                    'b64' => ValueUrlTool::encode($node['value']),
                ];
            }
        }
        $events = $this->events($user, $uuids['Event'], $objects, $attributes);

        $document['nodes'] = $visible;
        $document['view'] = (object)($document['view'] ?? []);
        $priorities = $this->model('ObjectTemplate')->uiPrioritiesFor($objects);
        return [
            'document' => $document,
            'Object' => $objects,
            'Attribute' => $attributes,
            'GalaxyCluster' => $clusters,
            'Value' => $values,
            'events' => $events ?: new stdClass(),
            'ui_priorities' => $priorities ?: new stdClass(),
            'edges' => $this->edges($user, $uuids['Event'], $objects, $attributes, $clusters, $values, $events),
            'meta' => [
                'nodes' => count($visible),
                'drawn' => count($drawn),
                'skipped' => array_map(['AnalystGraphDocumentTool', 'nodeKey'], $skipped),
                'budget' => $budget,
            ],
        ];
    }

    /**
     * What a thumbnail of the graph draws for this user: the first
     * THUMBNAIL_BUDGET nodes they may read, each at its saved position when it
     * has one, and the edges `data` derives between them. An attribute inside
     * a drawn object is the object, as the explorer draws it; hidden edges
     * stay hidden.
     *
     * @param array $user
     * @param array $document A stored graph document, decoded
     * @return array {nodes: [{key, type, x?, y?, pinned?}], edges: [[from, to, kind]], total}
     *               edges by index into nodes; total counts every node the user may read
     */
    public function thumbnail(array $user, array $document)
    {
        $payload = $this->resolve($user, $document, self::THUMBNAIL_BUDGET);
        $parent = [];
        foreach ($payload['Object'] as $object) {
            $objectKey = 'Object:' . strtolower($object['uuid']);
            foreach ($object['Attribute'] as $attribute) {
                $parent['Attribute:' . strtolower($attribute['uuid'])] = $objectKey;
            }
        }
        $nodes = [];
        $index = [];
        foreach (array_slice($payload['document']['nodes'], 0, self::THUMBNAIL_BUDGET) as $node) {
            $key = AnalystGraphDocumentTool::nodeKey($node);
            if (isset($parent[$key])) {
                continue;
            }
            $thumb = ['key' => $key, 'type' => $node['type']];
            if (isset($node['x'], $node['y']) && is_numeric($node['x']) && is_numeric($node['y'])) {
                $thumb['x'] = round((float)$node['x'], 1);
                $thumb['y'] = round((float)$node['y'], 1);
            }
            if (!empty($node['pinned'])) {
                $thumb['pinned'] = true;
            }
            $index[$key] = count($nodes);
            $nodes[] = $thumb;
        }
        $hidden = array_flip($document['hidden_edges'] ?? []);
        $edges = [];
        foreach ($payload['edges'] as $edge) {
            if ($edge['kind'] === 'contains' || isset($hidden[$edge['id']])) {
                continue;
            }
            $from = $index[$edge['from']] ?? $index[$parent[$edge['from']] ?? ''] ?? null;
            $to = $index[$edge['to']] ?? $index[$parent[$edge['to']] ?? ''] ?? null;
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            $pair = min($from, $to) . '-' . max($from, $to);
            if (!isset($edges[$pair])) {
                $edges[$pair] = [$from, $to, $edge['kind']];
            }
        }
        return [
            'nodes' => $nodes,
            'edges' => array_values($edges),
            'total' => $payload['meta']['nodes'],
        ];
    }

    /**
     * The nodes the user may read, in document order. A Value node is
     * authored content of the graph, so it is always readable.
     *
     * @param array $user
     * @param array $nodes Normalised nodes
     * @return array
     */
    public function visibleNodes(array $user, array $nodes)
    {
        return $this->visibleNodeLists($user, [$nodes])[0];
    }

    /**
     * visibleNodes() for several graphs, with one readability lookup per type
     * for all of them.
     *
     * @param array $user
     * @param array $lists key => normalised nodes
     * @return array key => the readable nodes, in document order
     */
    public function visibleNodeLists(array $user, array $lists)
    {
        $byType = [];
        foreach ($lists as $nodes) {
            foreach ($nodes as $node) {
                if ($node['type'] !== 'Value') {
                    $byType[$node['type']][$node['uuid']] = true;
                }
            }
        }
        $readable = [];
        $elements = $this->model('CollectionElement');
        foreach ($byType as $type => $uuids) {
            foreach ($elements->readableUuids($user, $type, array_keys($uuids)) as $uuid) {
                $readable[$type][strtolower($uuid)] = true;
            }
        }
        $out = [];
        foreach ($lists as $key => $nodes) {
            $out[$key] = array_values(array_filter($nodes, function ($node) use ($readable) {
                return $node['type'] === 'Value' || isset($readable[$node['type']][$node['uuid']]);
            }));
        }
        return $out;
    }

    /**
     * How many nodes of each graph the user may read: the only count of a
     * graph's nodes a user is ever shown.
     *
     * @param array $user
     * @param array $contents key => stored document, encoded or decoded
     * @return array key => int
     */
    public function visibleCounts(array $user, array $contents)
    {
        $lists = [];
        foreach ($contents as $key => $content) {
            $document = is_array($content) ? $content : json_decode((string)$content, true);
            $lists[$key] = is_array($document['nodes'] ?? null) ? $document['nodes'] : [];
        }
        return array_map('count', $this->visibleNodeLists($user, $lists));
    }

    /**
     * Give each graph a `target`: its type and uuid, and the id and label of
     * the record when the user can read it. An unreadable target keeps only
     * what the graph itself says.
     *
     * @param array $user
     * @param array $graphs Graph rows, unwrapped
     * @return array
     */
    public function labelTargets(array $user, array $graphs)
    {
        $uuidsByType = [];
        foreach ($graphs as $graph) {
            $uuidsByType[$graph['object_type']][] = strtolower($graph['object_uuid']);
        }
        $found = [];
        foreach ($uuidsByType as $type => $uuids) {
            $found[$type] = $this->targetRecords($user, $type, array_values(array_unique($uuids)));
        }
        foreach ($graphs as &$graph) {
            $type = $graph['object_type'];
            $uuid = strtolower($graph['object_uuid']);
            $graph['target'] = [
                'type' => $type,
                'uuid' => $uuid,
                'id' => $found[$type][$uuid]['id'] ?? null,
                'label' => $found[$type][$uuid]['label'] ?? null,
            ];
        }
        unset($graph);
        return $graphs;
    }

    /**
     * @return array lowercase uuid => {id, label}
     */
    private function targetRecords(array $user, $type, array $uuids)
    {
        $out = [];
        switch ($type) {
            case 'Event':
                $rows = $this->model('Event')->fetchSimpleEvents($user, [
                    'conditions' => ['Event.uuid' => $uuids],
                ]);
                foreach ($rows as $row) {
                    $out[strtolower($row['Event']['uuid'])] = ['id' => (int)$row['Event']['id'], 'label' => $row['Event']['info']];
                }
                break;
            case 'GalaxyCluster':
                $rows = $this->model('GalaxyCluster')->fetchGalaxyClusters($user, [
                    'conditions' => ['GalaxyCluster.uuid' => $uuids],
                    'fields' => ['GalaxyCluster.id', 'GalaxyCluster.uuid', 'GalaxyCluster.value'],
                    'contain' => [],
                ]);
                foreach ($rows as $row) {
                    $out[strtolower($row['GalaxyCluster']['uuid'])] = ['id' => (int)$row['GalaxyCluster']['id'], 'label' => $row['GalaxyCluster']['value']];
                }
                break;
            case 'Collection':
                $Collection = $this->model('Collection');
                $rows = $Collection->find('all', [
                    'conditions' => ['AND' => [['Collection.uuid' => $uuids], $Collection->buildConditions($user['id'])]],
                    'fields' => ['Collection.id', 'Collection.uuid', 'Collection.name'],
                    'recursive' => -1,
                ]);
                foreach ($rows as $row) {
                    $out[strtolower($row['Collection']['uuid'])] = ['id' => (int)$row['Collection']['id'], 'label' => $row['Collection']['name']];
                }
                break;
        }
        return $out;
    }

    private function uuidsByType(array $nodes)
    {
        $uuids = array_fill_keys(AnalystGraphDocumentTool::NODE_TYPES, []);
        foreach ($nodes as $node) {
            $uuids[$node['type']][] = $node['uuid'];
        }
        return $uuids;
    }

    /**
     * @return array in document order
     */
    private function objects(array $user, array $uuids)
    {
        if (empty($uuids)) {
            return [];
        }
        $found = $this->model('MispObject')->fetchGraphObjects($user, ['Object.uuid' => $uuids]);
        return $this->inOrder($found, $uuids);
    }

    private function attributes(array $user, array $uuids)
    {
        if (empty($uuids)) {
            return [];
        }
        $found = [];
        foreach ($this->model('MispAttribute')->fetchGraphAttributes($user, ['Attribute.uuid' => $uuids]) as $attribute) {
            $found[$attribute['uuid']] = $attribute;
        }
        return $this->inOrder($found, $uuids);
    }

    private function clusters(array $user, array $uuids)
    {
        if (empty($uuids)) {
            return [];
        }
        $rows = $this->model('GalaxyCluster')->fetchGalaxyClusters($user, [
            'conditions' => ['GalaxyCluster.uuid' => $uuids],
            'fields' => [
                'GalaxyCluster.id', 'GalaxyCluster.uuid', 'GalaxyCluster.tag_name',
                'GalaxyCluster.value', 'GalaxyCluster.type', 'GalaxyCluster.description',
                'GalaxyCluster.distribution', 'GalaxyCluster.deleted',
            ],
            'contain' => ['Galaxy' => ['fields' => ['Galaxy.id', 'Galaxy.name', 'Galaxy.type', 'Galaxy.icon', 'Galaxy.namespace']]],
        ]);
        $found = [];
        foreach ($rows as $row) {
            $found[$row['GalaxyCluster']['uuid']] = $row['GalaxyCluster'] + ['Galaxy' => $row['Galaxy'] ?? []];
        }
        return $this->inOrder($found, $uuids);
    }

    /**
     * Cards for the graph's Event nodes and for the events its records sit
     * in, keyed by event id.
     */
    private function events(array $user, array $uuids, array $objects, array $attributes)
    {
        $ids = [];
        if (!empty($uuids)) {
            $ids = array_values($this->model('Event')->find('list', [
                'conditions' => ['Event.uuid' => $uuids],
                'fields' => ['Event.uuid', 'Event.id'],
                'recursive' => -1,
            ]));
        }
        foreach (array_merge($objects, $attributes) as $record) {
            $ids[] = $record['event_id'];
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        return $this->model('Event')->correlatedEventCards($user, $ids);
    }

    /**
     * @param array $byUuid Records keyed by uuid, as stored
     * @param array $uuids Lowercase, in document order
     * @return array
     */
    private function inOrder(array $byUuid, array $uuids)
    {
        $lower = [];
        foreach ($byUuid as $uuid => $record) {
            $lower[strtolower($uuid)] = $record;
        }
        $out = [];
        foreach ($uuids as $uuid) {
            if (isset($lower[$uuid])) {
                $out[] = $lower[$uuid];
            }
        }
        return $out;
    }

    /**
     * The edges among the drawn nodes, each with an id that names it the
     * same way on every load and on every instance.
     *
     * @return array
     */
    private function edges(array $user, array $eventUuids, array $objects,
        array $attributes, array $clusters, array $values, array $events
    ) {
        $keys = [];
        foreach ($eventUuids as $uuid) {
            $keys['Event:' . $uuid] = true;
        }
        $objectUuidById = [];
        $ends = [];
        foreach ($objects as $object) {
            $uuid = strtolower($object['uuid']);
            $objectUuidById[$object['id']] = $uuid;
            $keys['Object:' . $uuid] = true;
            foreach ($object['Attribute'] as $attribute) {
                $ends[strtolower($attribute['uuid'])] = $attribute;
            }
        }
        foreach ($attributes as $attribute) {
            $ends[strtolower($attribute['uuid'])] = $attribute;
        }
        foreach (array_keys($ends) as $uuid) {
            $keys['Attribute:' . $uuid] = true;
        }
        $clusterByTag = [];
        foreach ($clusters as $cluster) {
            $keys['GalaxyCluster:' . strtolower($cluster['uuid'])] = true;
            $clusterByTag[$cluster['tag_name']] = strtolower($cluster['uuid']);
        }
        foreach ($values as $value) {
            $keys['Value:' . $value['uuid']] = true;
        }

        $edges = [];
        $add = function (array $edge) use (&$edges) {
            $edges[$edge['id']] = $edge;
        };

        foreach ($this->objectReferences($objectUuidById, $keys) as $edge) {
            $add($edge);
        }
        foreach ($this->relationships($user, $keys) as $edge) {
            $add($edge);
        }

        $eventNodes = array_flip($eventUuids);
        $eventUuidById = [];
        foreach ($events as $id => $card) {
            $eventUuidById[$id] = strtolower($card['uuid']);
        }
        $inEvent = function ($record, $type) use ($eventUuidById, $eventNodes, $add) {
            $eventUuid = $eventUuidById[$record['event_id']] ?? null;
            if ($eventUuid !== null && isset($eventNodes[$eventUuid])) {
                $uuid = strtolower($record['uuid']);
                $add([
                    'id' => 'in-event:' . $uuid,
                    'kind' => 'in-event',
                    'from' => $type . ':' . $uuid,
                    'to' => 'Event:' . $eventUuid,
                ]);
            }
        };
        foreach ($objects as $object) {
            $inEvent($object, 'Object');
        }
        foreach ($attributes as $attribute) {
            $uuid = strtolower($attribute['uuid']);
            if (!empty($attribute['object_id']) && isset($objectUuidById[$attribute['object_id']])) {
                $add([
                    'id' => 'contains:' . $uuid,
                    'kind' => 'contains',
                    'from' => 'Object:' . $objectUuidById[$attribute['object_id']],
                    'to' => 'Attribute:' . $uuid,
                ]);
            } else {
                $inEvent($attribute, 'Attribute');
            }
        }

        $tagged = function ($type, $uuid, $clusterUuid, $label) use ($add) {
            $add([
                'id' => 'tagged:' . $uuid . '>' . $clusterUuid,
                'kind' => 'tag',
                'from' => $type . ':' . $uuid,
                'to' => 'GalaxyCluster:' . $clusterUuid,
                'label' => $label,
            ]);
        };
        $drawnClusters = array_flip($clusterByTag);
        foreach ($events as $card) {
            $eventUuid = strtolower($card['uuid']);
            if (!isset($eventNodes[$eventUuid])) {
                continue;
            }
            foreach ($card['Galaxy'] as $galaxy) {
                foreach ($galaxy['GalaxyCluster'] as $cluster) {
                    $clusterUuid = strtolower($cluster['uuid']);
                    if (isset($drawnClusters[$clusterUuid])) {
                        $tagged('Event', $eventUuid, $clusterUuid, null);
                    }
                }
            }
        }
        foreach ($ends as $uuid => $attribute) {
            foreach ($attribute['Tag'] ?? [] as $tag) {
                if (isset($clusterByTag[$tag['name']])) {
                    $tagged('Attribute', $uuid, $clusterByTag[$tag['name']], $tag['relationship_type'] ?? null);
                }
            }
        }

        foreach ($this->clusterRelations($user, array_keys($drawnClusters)) as $edge) {
            $add($edge);
        }

        $valueUuids = [];
        foreach ($values as $value) {
            $valueUuids[mb_strtolower($value['value'])] = $value['uuid'];
        }
        if (!empty($valueUuids)) {
            foreach ($ends as $uuid => $attribute) {
                foreach ($this->valueParts($attribute) as $part) {
                    $valueUuid = $valueUuids[mb_strtolower($part)] ?? null;
                    if ($valueUuid !== null) {
                        $add([
                            'id' => 'value:' . $valueUuid . '>' . $uuid,
                            'kind' => 'value',
                            'from' => 'Value:' . $valueUuid,
                            'to' => 'Attribute:' . $uuid,
                        ]);
                    }
                }
            }
        }
        return array_values($edges);
    }

    /**
     * Live references from the drawn objects to drawn objects or attributes.
     * A reference belongs to its source object's event, so a readable source
     * makes it readable.
     */
    private function objectReferences(array $objectUuidById, array $keys)
    {
        if (empty($objectUuidById)) {
            return [];
        }
        $targets = [];
        foreach (array_keys($keys) as $key) {
            list($type, $uuid) = explode(':', $key, 2);
            if ($type === 'Object' || $type === 'Attribute') {
                $targets[] = $uuid;
            }
        }
        $rows = $this->model('ObjectReference')->find('all', [
            'conditions' => [
                'ObjectReference.object_id' => array_map('strval', array_keys($objectUuidById)),
                'ObjectReference.referenced_uuid' => $targets,
                'ObjectReference.deleted' => 0,
            ],
            'fields' => [
                'ObjectReference.uuid', 'ObjectReference.object_id',
                'ObjectReference.referenced_uuid', 'ObjectReference.referenced_type',
                'ObjectReference.relationship_type',
            ],
            'recursive' => -1,
        ]);
        $edges = [];
        foreach ($rows as $row) {
            $reference = $row['ObjectReference'];
            $to = ((int)$reference['referenced_type'] === 1 ? 'Object:' : 'Attribute:') . strtolower($reference['referenced_uuid']);
            if (!isset($keys[$to])) {
                continue;
            }
            $edges[] = [
                'id' => 'object-reference:' . $reference['uuid'],
                'kind' => 'object-reference',
                'from' => 'Object:' . $objectUuidById[$reference['object_id']],
                'to' => $to,
                'label' => $reference['relationship_type'],
                'uuid' => $reference['uuid'],
            ];
        }
        return $edges;
    }

    /**
     * Analyst-data relationships the user may read with both ends drawn.
     */
    private function relationships(array $user, array $keys)
    {
        $uuids = [];
        foreach (array_keys($keys) as $key) {
            $uuids[] = explode(':', $key, 2)[1];
        }
        $uuids = array_values(array_unique($uuids));
        if (empty($uuids)) {
            return [];
        }
        $relationship = $this->model('Relationship');
        $conditions = [
            'Relationship.object_uuid' => $uuids,
            'Relationship.related_object_uuid' => $uuids,
        ];
        $acl = $relationship->buildConditions($user);
        if (!empty($acl)) {
            $conditions['AND'][] = $acl;
        }
        $rows = $relationship->find('all', [
            'conditions' => $conditions,
            'fields' => [
                'Relationship.uuid', 'Relationship.object_uuid', 'Relationship.object_type',
                'Relationship.related_object_uuid', 'Relationship.related_object_type',
                'Relationship.relationship_type', 'Relationship.authors',
                'Relationship.orgc_uuid', 'Relationship.distribution',
            ],
            'recursive' => -1,
            'callbacks' => false,
        ]);
        $edges = [];
        foreach ($rows as $row) {
            $r = $row['Relationship'];
            $from = $r['object_type'] . ':' . strtolower($r['object_uuid']);
            $to = $r['related_object_type'] . ':' . strtolower($r['related_object_uuid']);
            if (!isset($keys[$from], $keys[$to])) {
                continue;
            }
            $edges[] = [
                'id' => 'relationship:' . $r['uuid'],
                'kind' => 'relationship',
                'from' => $from,
                'to' => $to,
                'label' => $r['relationship_type'],
                'uuid' => $r['uuid'],
                'authors' => $r['authors'],
                'orgc_uuid' => $r['orgc_uuid'],
                'distribution' => $r['distribution'],
            ];
        }
        return $edges;
    }

    /**
     * Relations the user may read between drawn clusters. A relation has no
     * uuid, so its ends and type name it.
     */
    private function clusterRelations(array $user, array $clusterUuids)
    {
        if (count($clusterUuids) < 2) {
            return [];
        }
        $rows = $this->model('GalaxyClusterRelation')->fetchRelations($user, [
            'conditions' => [
                'GalaxyClusterRelation.galaxy_cluster_uuid' => $clusterUuids,
                'GalaxyClusterRelation.referenced_galaxy_cluster_uuid' => $clusterUuids,
            ],
        ]);
        $edges = [];
        foreach ($rows as $row) {
            $relation = $row['GalaxyClusterRelation'];
            $from = strtolower($relation['galaxy_cluster_uuid']);
            $to = strtolower($relation['referenced_galaxy_cluster_uuid']);
            $type = $relation['referenced_galaxy_cluster_type'];
            $edges[] = [
                'id' => 'cluster-relation:' . $from . '>' . $to . ':' . $type,
                'kind' => 'cluster-relation',
                'from' => 'GalaxyCluster:' . $from,
                'to' => 'GalaxyCluster:' . $to,
                'label' => $type,
            ];
        }
        return $edges;
    }

    /**
     * The halves a value page matches an attribute on: value1 and value2.
     *
     * @param array $attribute
     * @return string[]
     */
    private function valueParts(array $attribute)
    {
        if (isset($attribute['value1'])) {
            $parts = [$attribute['value1'], $attribute['value2'] ?? ''];
        } else {
            $parts = strpos($attribute['type'] ?? '', '|') !== false
                ? explode('|', (string)$attribute['value'], 2)
                : [(string)($attribute['value'] ?? '')];
        }
        return array_values(array_filter(array_map('strval', $parts), 'strlen'));
    }
}
