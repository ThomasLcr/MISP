<?php
App::uses('AppController', 'Controller');
App::uses('AnalystGraphDocumentTool', 'Tools');
App::uses('JsonTool', 'Tools');
App::uses('MispTheme', 'MispTheme');
App::uses('Graph', 'Model');

/**
 * The actions only an analyst graph has. Creating, editing, viewing and
 * deleting one go through AnalystDataController with the type `Graph`.
 */
class AnalystGraphsController extends AppController
{
    public $components = ['Session', 'RequestHandler'];

    public $uses = ['Graph', 'AnalystGraphData'];

    /** The only theme that carries the graph page. */
    const THEME = 'Overmind';

    /** Collections offered as a fork's other target. */
    const FORK_COLLECTIONS = 50;

    /** Graphs a record's Graphs card lists. */
    const TARGET_LIMIT = 100;

    public function beforeFilter()
    {
        parent::beforeFilter();
        // Posted as hand-built JSON by the graph host, with the CSRF token
        // in the X-CSRF-Token header.
        $this->_csrfTokenHeaderOnly(['save', 'addNodes', 'removeNodes', 'fork', 'active']);
    }

    public function beforeRender()
    {
        parent::beforeRender();
        if (!MispTheme::carries($this->theme, 'AnalystGraphs')) {
            $this->theme = self::THEME;
            $this->viewClass = 'Theme';
        }
    }

    /**
     * The graph's own page: the explorer at full size, where its editors
     * save it and everyone else may fork it.
     *
     * @param string $uuid
     */
    public function view($uuid)
    {
        $this->request->allowMethod(['get']);
        $user = $this->Auth->user();
        $graph = $this->__fetchGraph($uuid);
        $summary = $this->Graph->summaries($user, ['Graph.id' => $graph['Graph']['id']], ['parents' => true])[0];

        $canAnalyst = !empty($user['Role']['perm_site_admin'])
            || (!empty($user['Role']['perm_add']) && !empty($user['Role']['perm_analyst_data']));
        $this->set('graph', $summary);
        $this->set('canEdit', !empty($summary['_canEdit']));
        $this->set('canFork', $canAnalyst);
        $labels = ClassRegistry::init('AnalystProfile')->pivotLabels($user);
        $this->set('explorer', [
            'canEdit' => !empty($user['Role']['perm_site_admin']) || !empty($user['Role']['perm_modify']),
            'canAnalyst' => $canAnalyst,
            'analystSharing' => $canAnalyst ? $this->__analystSharing($user, $summary) : null,
            'labelPlan' => $labels['plan'],
            'permitted' => $labels['permitted'],
        ]);
        $this->set('forkTargets', $canAnalyst ? $this->__forkTargets($user, $summary) : []);
        if (!empty($summary['target']['label'])) {
            $this->set('intelGraphPage', [
                'type' => $summary['target']['type'],
                'uuid' => $summary['target']['uuid'],
                'label' => $summary['target']['label'],
            ]);
        }
        $this->set('title_for_layout', $summary['name']);
    }
    /**
     * The graph "Add to graph" feeds. POST `{graph_uuid}` sets it to a graph
     * the user may edit, or clears it with null.
     */
    public function active()
    {
        $this->request->allowMethod(['get', 'post']);
        $user = $this->Auth->user();
        if ($this->request->is('get')) {
            return $this->RestResponse->viewData(['Graph' => $this->Graph->activeFor($user)], 'json');
        }
        $input = $this->__input();
        if (!array_key_exists('graph_uuid', $input) || ($input['graph_uuid'] !== null && !is_string($input['graph_uuid']))) {
            throw new BadRequestException(__('Name the graph to add to, or null for none.'));
        }
        $graph = null;
        if ($input['graph_uuid'] !== null) {
            $graph = $this->__fetchEditableGraph($input['graph_uuid']);
            $graph = $this->Graph->summaries($user, ['Graph.id' => $graph['Graph']['id']])[0];
        }
        if (!$this->Graph->storeActive($user, $graph ? $graph['uuid'] : null)) {
            throw new InternalErrorException(__('The active graph could not be saved.'));
        }
        return $this->RestResponse->viewData(['Graph' => $graph], 'json');
    }

    /**
     * The graphs hung off one record that the user may read, newest first,
     * and the user's active graph.
     *
     * @param string $type
     * @param string $uuid
     */
    public function forTarget($type, $uuid)
    {
        $this->request->allowMethod(['get']);
        if (!in_array($type, Graph::VALID_TARGETS, true) || !Validation::uuid($uuid)) {
            throw new NotFoundException(__('Invalid target.'));
        }
        $user = $this->Auth->user();
        // Stored as each creator spelled it
        $spellings = array_values(array_unique([$uuid, strtolower($uuid), strtoupper($uuid)]));
        $graphs = $this->Graph->summaries($user, [
            'Graph.object_type' => $type,
            'Graph.object_uuid' => $spellings,
        ], ['targets' => false, 'parents' => true, 'limit' => self::TARGET_LIMIT]);
        $active = $this->ACL->canUserAccess($user, 'analystGraphs', 'active')
            ? $this->Graph->activeFor($user, false)
            : null;
        return $this->RestResponse->viewData([
            'Graph' => $graphs,
            'active' => $active ? $active['uuid'] : null,
        ], 'json');
    }

    /**
     * The organisation's graphs the user may edit, newest first, without
     * their documents.
     */
    public function editable()
    {
        $this->request->allowMethod(['get']);
        $user = $this->Auth->user();
        $active = $this->Graph->activeFor($user, false);
        return $this->RestResponse->viewData([
            'Graph' => $this->Graph->editableBy($user),
            'active' => $active ? $active['uuid'] : null,
        ], 'json');
    }

    /**
     * The graph as the user sees it: its document, the records of the nodes
     * they may read and the edges between them.
     *
     * @param string $uuid
     */
    public function data($uuid)
    {
        $this->request->allowMethod(['get']);
        $graph = $this->__fetchGraph($uuid);
        $document = json_decode($graph['Graph']['content'], true) ?: AnalystGraphDocumentTool::emptyDocument();
        unset($graph['Graph']['content']);
        $payload = $this->AnalystGraphData->resolve($this->Auth->user(), $document);
        // Counted and measured as this user sees it (G6)
        $graph['Graph']['node_count'] = $payload['meta']['nodes'];
        unset($graph['Graph']['content_size']);
        $payload['editable_events'] = $this->__editableEvents(array_keys((array)$payload['events']));
        return $this->RestResponse->viewData(['Graph' => $this->Graph->typed($graph['Graph'])] + $payload, 'json');
    }

    /**
     * What a thumbnail of the graph draws for this user (AnalystGraphData::thumbnail).
     *
     * @param string $uuid
     */
    public function thumbnail($uuid)
    {
        $this->request->allowMethod(['get']);
        // Read-only, and asked for several at a time: it leaves the session
        // as it found it
        $user = $this->_closeSession();
        $graph = $this->__fetchGraph($uuid);
        $document = json_decode($graph['Graph']['content'], true) ?: AnalystGraphDocumentTool::emptyDocument();
        return $this->RestResponse->viewData([
            'uuid' => $graph['Graph']['uuid'],
            'revision' => (int)$graph['Graph']['revision'],
        ] + $this->AnalystGraphData->thumbnail($user, $document), 'json');
    }

    /**
     * Replace the document, unless the graph was saved since the revision the
     * client started from.
     *
     * @param string $uuid
     */
    public function save($uuid)
    {
        $this->request->allowMethod(['post', 'put']);
        $graph = $this->__fetchEditableGraph($uuid);
        $input = $this->__input();
        $revision = $input['revision'] ?? null;
        if (!is_int($revision) && !(is_string($revision) && ctype_digit($revision))) {
            throw new BadRequestException(__('A save names the revision it started from.'));
        }
        if (!isset($input['content']) || (!is_array($input['content']) && !is_string($input['content']))) {
            throw new BadRequestException(__('A save carries the graph document as content.'));
        }
        $content = $input['content'];
        $result = $this->Graph->writeContent($graph['Graph']['id'], function () use ($content) {
            return $content;
        }, (int)$revision);
        return $this->__writeResponse($graph, $result);
    }

    /**
     * Add records to the stored document, without the client loading it.
     *
     * @param string $uuid
     */
    public function addNodes($uuid)
    {
        $this->request->allowMethod(['post']);
        $graph = $this->__fetchEditableGraph($uuid);
        $items = $this->__items();
        $report = null;
        $result = $this->Graph->writeContent($graph['Graph']['id'], function (array $document) use ($items, &$report) {
            list($document, $report) = AnalystGraphDocumentTool::addNodes($document, $items);
            return empty($report['added']) ? null : $document;
        });
        return $this->__writeResponse($graph, $result, $report);
    }

    /**
     * Remove records from the stored document, as the undo of addNodes().
     *
     * @param string $uuid
     */
    public function removeNodes($uuid)
    {
        $this->request->allowMethod(['post']);
        $graph = $this->__fetchEditableGraph($uuid);
        $items = $this->__items();
        $report = null;
        $result = $this->Graph->writeContent($graph['Graph']['id'], function (array $document) use ($items, &$report) {
            list($document, $report) = AnalystGraphDocumentTool::removeNodes($document, $items);
            return empty($report['removed']) ? null : $document;
        });
        return $this->__writeResponse($graph, $result, $report);
    }

    /**
     * A copy of the graph owned by the user's organisation, holding the nodes
     * they can see. Its distribution is the analyst-data default.
     *
     * @param string $uuid
     */
    public function fork($uuid)
    {
        $this->request->allowMethod(['post']);
        $user = $this->Auth->user();
        $original = $this->__fetchGraph($uuid);
        $input = $this->__input();
        $document = json_decode($original['Graph']['content'], true) ?: AnalystGraphDocumentTool::emptyDocument();
        $document['nodes'] = $this->AnalystGraphData->visibleNodes($user, $document['nodes'] ?? []);
        $target = isset($input['target']) && is_array($input['target']) ? $input['target'] : [];
        $fork = ['Graph' => [
            'name' => isset($input['name']) && is_string($input['name']) ? $input['name'] : $original['Graph']['name'],
            'description' => $original['Graph']['description'],
            'object_uuid' => $target['uuid'] ?? $original['Graph']['object_uuid'],
            'object_type' => $target['type'] ?? $original['Graph']['object_type'],
            'content' => $document,
            'forked_from_uuid' => $original['Graph']['uuid'],
        ]];
        $this->Graph->current_user = $user;
        $this->Graph->create();
        if (!$this->Graph->save($fork)) {
            return $this->RestResponse->saveFailResponse('AnalystGraphs', 'fork', false, $this->Graph->validationErrors, 'json');
        }
        $id = $this->Graph->id;
        $created = $this->Graph->summaries($user, ['Graph.id' => $id])[0];
        $created['content'] = AnalystGraphDocumentTool::decode($this->Graph->storedContents([$id])[$id]);
        return $this->RestResponse->viewData(['Graph' => $created], 'json');
    }

    /**
     * @param string $uuid
     * @return array
     * @throws NotFoundException
     */
    private function __fetchGraph($uuid)
    {
        if (!Validation::uuid($uuid)) {
            throw new NotFoundException(__('Invalid graph.'));
        }
        $user = $this->Auth->user();
        $this->Graph->current_user = $user;
        $graph = $this->Graph->find('first', [
            'conditions' => [
                'AND' => [
                    ['Graph.uuid' => $uuid],
                    $this->Graph->buildConditions($user),
                ],
            ],
            'contain' => ['Org', 'Orgc'],
        ]);
        if (empty($graph)) {
            throw new NotFoundException(__('Invalid graph.'));
        }
        return $graph;
    }

    /**
     * @param string $uuid
     * @return array
     * @throws ForbiddenException
     */
    private function __fetchEditableGraph($uuid)
    {
        $graph = $this->__fetchGraph($uuid);
        if (empty($graph['Graph']['_canEdit'])) {
            throw new ForbiddenException(__('Only the organisation that created this graph can change it. Fork it instead.'));
        }
        return $graph;
    }

    /**
     * What a relationship drawn in the graph can be shared with, as
     * analystData/add offers it, defaulting to the graph's own distribution.
     *
     * @param array $user
     * @param array $graph Summary
     * @return array
     */
    private function __analystSharing(array $user, array $graph)
    {
        $levels = [];
        foreach (ClassRegistry::init('Event')->distributionLevels as $level => $name) {
            if ($level <= 4) {
                $levels[] = [(int)$level, $name];
            }
        }
        $sharingGroups = [];
        $authorised = ClassRegistry::init('SharingGroup')->fetchAllAuthorised($user, 'name', 1);
        asort($authorised);
        foreach ($authorised as $id => $name) {
            $sharingGroups[] = [(int)$id, $name];
        }
        return [
            'levels' => $levels,
            'sharingGroups' => $sharingGroups,
            'default' => (int)$graph['distribution'],
            'sharingGroup' => $graph['sharing_group_id'],
            'authors' => $user['email'] ?? '',
        ];
    }

    /**
     * Where a fork can land: the original's target when the user can read
     * it, then the collections their organisation created (Q8).
     *
     * @param array $user
     * @param array $graph Summary, its target labelled
     * @return array [{type, uuid, label}]
     */
    private function __forkTargets(array $user, array $graph)
    {
        $targets = [];
        if (!empty($graph['target']['label'])) {
            $targets[] = [
                'type' => $graph['target']['type'],
                'uuid' => $graph['target']['uuid'],
                'label' => $graph['target']['label'],
            ];
        }
        $collections = ClassRegistry::init('Collection')->find('all', [
            'conditions' => ['Collection.orgc_id' => $user['org_id']],
            'fields' => ['Collection.uuid', 'Collection.name'],
            'order' => ['Collection.modified' => 'DESC'],
            'limit' => self::FORK_COLLECTIONS,
            'recursive' => -1,
        ]);
        foreach ($collections as $collection) {
            $uuid = strtolower($collection['Collection']['uuid']);
            if (!empty($targets) && $targets[0]['uuid'] === $uuid) {
                continue;
            }
            $targets[] = ['type' => 'Collection', 'uuid' => $uuid, 'label' => $collection['Collection']['name']];
        }
        return $targets;
    }

    /**
     * Of these events, the ones the user may modify: where an edge drawn in
     * the graph can be an object reference.
     *
     * @param array $ids
     * @return int[]
     */
    private function __editableEvents(array $ids)
    {
        $user = $this->Auth->user();
        if (empty($ids) || (empty($user['Role']['perm_modify']) && empty($user['Role']['perm_site_admin']))) {
            return [];
        }
        $events = ClassRegistry::init('Event')->find('all', [
            'conditions' => ['Event.id' => array_map('strval', $ids)],
            'fields' => ['Event.id', 'Event.orgc_id', 'Event.user_id'],
            'recursive' => -1,
        ]);
        $editable = [];
        foreach ($events as $event) {
            if ($this->ACL->canModifyEvent($user, $event)) {
                $editable[] = (int)$event['Event']['id'];
            }
        }
        return $editable;
    }

    /**
     * @return array
     */
    private function __input()
    {
        $input = $this->request->data;
        if (!is_array($input)) {
            return [];
        }
        return isset($input['Graph']) && is_array($input['Graph']) ? $input['Graph'] : $input;
    }

    /**
     * @return array
     * @throws BadRequestException
     */
    private function __items()
    {
        $items = $this->__input()['items'] ?? null;
        if (!is_array($items) || empty($items) || array_keys($items) !== range(0, count($items) - 1)) {
            throw new BadRequestException(__('A list of items is required.'));
        }
        if (count($items) > AnalystGraphDocumentTool::MAX_NODES) {
            throw new BadRequestException(__('At most %s items at once.', AnalystGraphDocumentTool::MAX_NODES));
        }
        return $items;
    }

    /**
     * @param array $graph
     * @param array $result Graph::writeContent()'s
     * @param array|null $report What addNodes() or removeNodes() did
     * @return CakeResponse
     */
    private function __writeResponse(array $graph, array $result, $report = null)
    {
        switch ($result['status']) {
            case 'missing':
                throw new NotFoundException(__('Invalid graph.'));
            case 'conflict':
                $this->response->statusCode(409);
                $this->response->type('json');
                $this->response->body(JsonTool::encode([
                    'saved' => false,
                    'name' => __('The graph was saved since you loaded it.'),
                    'message' => __('The graph was saved since you loaded it.'),
                    'url' => $this->request->here,
                    'revision' => $result['revision'],
                ]));
                return $this->response;
            case 'invalid':
                return $this->RestResponse->saveFailResponse('AnalystGraphs', $this->request->params['action'], $graph['Graph']['id'], $result['errors'], 'json');
        }
        $id = $graph['Graph']['id'];
        $visible = $this->AnalystGraphData->visibleCounts($this->Auth->user(), $this->Graph->storedContents([$id]));
        $response = [
            'saved' => true,
            'changed' => $result['status'] === 'saved',
            'uuid' => $graph['Graph']['uuid'],
            'revision' => $result['revision'],
            'node_count' => $visible[$id] ?? 0,
        ];
        if ($result['status'] === 'saved') {
            $response['content_size'] = $result['content_size'];
            $response['modified'] = $result['modified'];
        }
        return $this->RestResponse->viewData($response + (array)$report, 'json');
    }
}
