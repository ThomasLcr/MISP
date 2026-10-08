<?php
App::uses('Value', 'Model');

/**
 * The document an analyst graph stores in its `content` column: which records
 * it shows and where they sit, never copies of them and no edges of its own.
 *
 *     {
 *       "version": 1,
 *       "nodes": [{"type": "Attribute", "uuid": "…", "x": 120, "y": -40, "pinned": true},
 *                 {"type": "Value", "uuid": "…", "value": "8.8.8.8"}],
 *       "hidden_edges": ["relationship:<uuid>"],
 *       "view": {"layout": "force", "zoom": 1.0, "center": [0, 0]}
 *     }
 */
class AnalystGraphDocumentTool
{
    const VERSION = 1;
    const NODE_TYPES = ['Event', 'Attribute', 'Object', 'GalaxyCluster', 'Value'];
    const MAX_NODES = 2000;
    const MAX_BYTES = 16777216;
    const MAX_VALUE_BYTES = 1024;
    const MAX_EDGE_ID_LENGTH = 255;
    const MAX_LAYOUT_LENGTH = 32;
    const SUMMARY_LIST_LIMIT = 100;

    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array
     */
    public static function emptyDocument()
    {
        return [
            'version' => self::VERSION,
            'nodes' => [],
            'hidden_edges' => [],
            'view' => [],
        ];
    }

    /**
     * Validate a document and bring it to its stored form: keys it does not
     * define are dropped, node uuids are lowercased, and a Value node's uuid is
     * recomputed from its literal.
     *
     * @param array|string|null $content
     * @return array [array|null $document, string[] $errors]
     */
    public static function normalise($content)
    {
        if (is_string($content)) {
            $content = json_decode($content, true);
            if (!is_array($content)) {
                return [null, [__('The graph document is not valid JSON.')]];
            }
        }
        if (!is_array($content)) {
            return [null, [__('The graph document must be an object.')]];
        }

        $errors = [];
        $version = $content['version'] ?? self::VERSION;
        if ($version !== self::VERSION) {
            $errors[] = __('Unsupported graph document version.');
        }

        $nodes = $content['nodes'] ?? [];
        if (!self::isList($nodes)) {
            $errors[] = __('nodes must be a list.');
            $nodes = [];
        }
        if (count($nodes) > self::MAX_NODES) {
            $errors[] = __('A graph holds at most %s nodes.', self::MAX_NODES);
            $nodes = [];
        }
        $document = self::emptyDocument();
        $seen = [];
        foreach ($nodes as $i => $node) {
            $nodeErrors = [];
            $node = self::normaliseNode($node, $nodeErrors);
            foreach ($nodeErrors as $error) {
                $errors[] = sprintf('nodes[%s]: %s', $i, $error);
            }
            if ($node === null) {
                continue;
            }
            $key = self::nodeKey($node);
            if (isset($seen[$key])) {
                $errors[] = sprintf('nodes[%s]: %s', $i, __('duplicate of nodes[%s].', $seen[$key]));
                continue;
            }
            $seen[$key] = $i;
            $document['nodes'][] = $node;
        }

        $hiddenEdges = $content['hidden_edges'] ?? [];
        if (!self::isList($hiddenEdges)) {
            $errors[] = __('hidden_edges must be a list.');
            $hiddenEdges = [];
        }
        foreach ($hiddenEdges as $i => $edgeId) {
            if (!is_string($edgeId) || $edgeId === '' || strlen($edgeId) > self::MAX_EDGE_ID_LENGTH) {
                $errors[] = sprintf('hidden_edges[%s]: %s', $i, __('must be a non-empty string of at most %s characters.', self::MAX_EDGE_ID_LENGTH));
                continue;
            }
            $document['hidden_edges'][] = $edgeId;
        }
        $document['hidden_edges'] = array_values(array_unique($document['hidden_edges']));

        $view = $content['view'] ?? [];
        if (!is_array($view) || (!empty($view) && self::isList($view))) {
            $errors[] = __('view must be an object.');
            $view = [];
        }
        $document['view'] = self::normaliseView($view, $errors);

        if (!empty($errors)) {
            return [null, $errors];
        }
        if (strlen(self::encode($document)) > self::MAX_BYTES) {
            return [null, [__('A graph document is at most %s bytes.', self::MAX_BYTES)]];
        }
        return [$document, []];
    }

    /**
     * @param array $document
     * @return string
     */
    public static function encode(array $document)
    {
        $document['view'] = (object)($document['view'] ?? []);
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Stored content as the API returns it, `view` kept an object when empty.
     *
     * @param string $content
     * @return array|null
     */
    public static function decode($content)
    {
        $document = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($document)) {
            return null;
        }
        $document['view'] = (object)($document['view'] ?? []);
        return $document;
    }

    /**
     * The columns derived from stored content.
     *
     * @param string $content
     * @return array
     */
    public static function measure($content)
    {
        return [
            'content_size' => strlen($content),
            'node_count' => count(self::storedNodeKeys($content)),
        ];
    }

    /**
     * @param array $node A normalised node
     * @return string
     */
    public static function nodeKey(array $node)
    {
        return $node['type'] . ':' . $node['uuid'];
    }

    /**
     * Append nodes to a stored document. A node already in it is reported
     * present, one that is malformed or that the document has no room for is
     * refused.
     *
     * @param array $document A stored document, decoded
     * @param array $items Nodes, as the document holds them
     * @return array [array $document, array $report]
     */
    public static function addNodes(array $document, array $items)
    {
        $report = ['added' => [], 'present' => [], 'refused' => []];
        $nodes = isset($document['nodes']) && is_array($document['nodes']) ? $document['nodes'] : [];
        $seen = array_flip(self::nodeKeysOf($nodes));
        foreach (array_values($items) as $i => $item) {
            $errors = [];
            $node = self::normaliseNode($item, $errors);
            if ($node === null) {
                $report['refused'][] = ['index' => $i, 'error' => implode(' ', $errors)];
                continue;
            }
            $key = self::nodeKey($node);
            if (isset($seen[$key])) {
                $report['present'][] = $key;
                continue;
            }
            if (count($nodes) >= self::MAX_NODES) {
                $report['refused'][] = ['index' => $i, 'error' => __('A graph holds at most %s nodes.', self::MAX_NODES)];
                continue;
            }
            $seen[$key] = true;
            $nodes[] = $node;
            $report['added'][] = $key;
        }
        $document['nodes'] = $nodes;
        return [$document, $report];
    }

    /**
     * Remove nodes from a stored document, named as nodes or as the
     * `Type:uuid` keys addNodes() reports.
     *
     * @param array $document A stored document, decoded
     * @param array $items
     * @return array [array $document, array $report]
     */
    public static function removeNodes(array $document, array $items)
    {
        $report = ['removed' => [], 'absent' => [], 'refused' => []];
        $wanted = [];
        foreach (array_values($items) as $i => $item) {
            $key = self::keyOf($item);
            if ($key === null) {
                $report['refused'][] = ['index' => $i, 'error' => __('not a node.')];
                continue;
            }
            $wanted[$key] = true;
        }
        $kept = [];
        foreach ($document['nodes'] ?? [] as $node) {
            $key = self::nodeKeysOf([$node])[0] ?? null;
            if ($key !== null && isset($wanted[$key])) {
                $report['removed'][] = $key;
                unset($wanted[$key]);
                continue;
            }
            $kept[] = $node;
        }
        $report['absent'] = array_keys($wanted);
        $document['nodes'] = $kept;
        return [$document, $report];
    }

    /**
     * @param mixed $item A node, or a `Type:uuid` key
     * @return string|null
     */
    private static function keyOf($item)
    {
        if (is_string($item)) {
            $parts = explode(':', $item, 2);
            $item = count($parts) === 2 ? ['type' => $parts[0], 'uuid' => $parts[1]] : null;
        }
        if (!is_array($item) || !isset($item['type']) || !in_array($item['type'], self::NODE_TYPES, true)) {
            return null;
        }
        if ($item['type'] === 'Value' && isset($item['value']) && is_scalar($item['value'])) {
            $value = trim((string)$item['value']);
            return $value === '' ? null : 'Value:' . Value::uuidFor($value);
        }
        if (!isset($item['uuid']) || !is_string($item['uuid']) || !preg_match(self::UUID_PATTERN, $item['uuid'])) {
            return null;
        }
        return $item['type'] . ':' . strtolower($item['uuid']);
    }

    /**
     * What the audit log records for a content change in place of the
     * document: node counts, and which nodes came and went.
     *
     * @param string|null $old Stored content before the change
     * @param string|null $new Stored content after it
     * @return array
     */
    public static function summariseChange($old, $new)
    {
        $newKeys = self::storedNodeKeys($new);
        $summary = [
            'node_count' => count($newKeys),
            'content_size' => strlen((string)$new),
        ];
        if ($old === null) {
            return $summary;
        }
        $oldKeys = self::storedNodeKeys($old);
        $summary['node_count'] = [count($oldKeys), count($newKeys)];
        $summary['content_size'] = [strlen($old), strlen((string)$new)];
        foreach (['nodes_added' => array_diff($newKeys, $oldKeys), 'nodes_removed' => array_diff($oldKeys, $newKeys)] as $field => $keys) {
            $keys = array_values($keys);
            $summary[$field . '_count'] = count($keys);
            $summary[$field] = array_slice($keys, 0, self::SUMMARY_LIST_LIMIT);
        }
        return $summary;
    }

    /**
     * @param string|null $content
     * @return string[]
     */
    private static function storedNodeKeys($content)
    {
        $document = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($document) || !isset($document['nodes']) || !is_array($document['nodes'])) {
            return [];
        }
        return self::nodeKeysOf($document['nodes']);
    }

    /**
     * @param array $nodes
     * @return string[]
     */
    private static function nodeKeysOf(array $nodes)
    {
        $keys = [];
        foreach ($nodes as $node) {
            if (isset($node['type'], $node['uuid'])) {
                $keys[] = $node['type'] . ':' . $node['uuid'];
            }
        }
        return $keys;
    }

    /**
     * @param mixed $node
     * @param string[] $errors
     * @return array|null
     */
    private static function normaliseNode($node, array &$errors)
    {
        if (!is_array($node)) {
            $errors[] = __('must be an object.');
            return null;
        }
        $type = $node['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::NODE_TYPES, true)) {
            $errors[] = __('unknown node type.');
            return null;
        }
        $normalised = ['type' => $type];
        if ($type === 'Value') {
            $value = isset($node['value']) && is_scalar($node['value']) ? trim((string)$node['value']) : '';
            if ($value === '') {
                $errors[] = __('a Value node needs a value.');
                return null;
            }
            if (strlen($value) > self::MAX_VALUE_BYTES) {
                $errors[] = __('a Value node holds at most %s bytes.', self::MAX_VALUE_BYTES);
                return null;
            }
            $normalised['uuid'] = Value::uuidFor($value);
            $normalised['value'] = $value;
        } else {
            $uuid = $node['uuid'] ?? null;
            if (!is_string($uuid) || !preg_match(self::UUID_PATTERN, $uuid)) {
                $errors[] = __('invalid uuid.');
                return null;
            }
            $normalised['uuid'] = strtolower($uuid);
        }
        foreach (['x', 'y'] as $axis) {
            if (!isset($node[$axis])) {
                continue;
            }
            if (!self::isFiniteNumber($node[$axis])) {
                $errors[] = __('%s must be a number.', $axis);
                return null;
            }
            $normalised[$axis] = $node[$axis];
        }
        foreach (['pinned', 'collapsed'] as $flag) {
            if (!empty($node[$flag])) {
                $normalised[$flag] = true;
            }
        }
        return $normalised;
    }

    /**
     * @param array $view
     * @param string[] $errors
     * @return array
     */
    private static function normaliseView(array $view, array &$errors)
    {
        $normalised = [];
        if (isset($view['layout'])) {
            if (is_string($view['layout']) && $view['layout'] !== '' && strlen($view['layout']) <= self::MAX_LAYOUT_LENGTH) {
                $normalised['layout'] = $view['layout'];
            } else {
                $errors[] = __('view.layout must be a short string.');
            }
        }
        if (isset($view['zoom'])) {
            if (self::isFiniteNumber($view['zoom']) && $view['zoom'] > 0) {
                $normalised['zoom'] = $view['zoom'];
            } else {
                $errors[] = __('view.zoom must be a positive number.');
            }
        }
        if (isset($view['center'])) {
            $center = $view['center'];
            if (is_array($center) && self::isList($center) && count($center) === 2 && self::isFiniteNumber($center[0]) && self::isFiniteNumber($center[1])) {
                $normalised['center'] = [$center[0], $center[1]];
            } else {
                $errors[] = __('view.center must be [x, y].');
            }
        }
        return $normalised;
    }

    private static function isFiniteNumber($value)
    {
        return (is_int($value) || is_float($value)) && is_finite($value);
    }

    private static function isList($value)
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }
}
