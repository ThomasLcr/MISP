<?php
/**
 * The Occurrences tab, drawn as the event view's Attributes tab: the
 * shared index filter bar, the count line and pager, then the Overview
 * card's table over one page of the value's occurrences.
 *
 * Filtered and paged on the server (ValuesController::viewOccurrenceTable);
 * the bar reloads this fragment in its tab. The pickers offer what the
 * reader's occurrences carry, counted by `Value::occurrenceFacetsFor`.
 *
 * @var array $valueIntelligence
 * @var string $valueB64
 * @var array $filterLabels IndexPicker::resolve() for the chosen tags and clusters
 */
App::uses('DistributionLevel', 'Tools');
App::uses('IndexFilterState', 'Tools');

$profile = $valueIntelligence;
$rows = $profile['occurrences'];
$stats = $profile['occurrence_stats'];
$facets = $profile['occurrence_facets'];
$named = $this->request->params['named'] ?? array();
$tabUrl = $baseurl . '/values/viewOccurrenceTable/' . $valueB64;
$filterLabels = $filterLabels ?? array('tag' => array(), 'galaxy' => array());
$canGraph = $this->Acl->canAccess('workspaces', 'addNodes');

$group = __('Occurrences');
$dropdown = function ($name, $label, array $options, $icon = null) use ($group) {
    return array(
        'type' => 'dropdown',
        'name' => $name,
        'label' => $label,
        'icon' => $icon,
        // The empty option's label is the control's placeholder.
        'options' => array('' => __('Any')) + $options,
        'group' => $group,
    );
};
$facet = function ($key) use ($facets) {
    return isset($facets[$key]) ? $facets[$key] : array();
};

$orgs = array();
foreach ($facet('org') as $orgId => $count) {
    $orgs[(string)$orgId] = $facets['org_names'][$orgId] ?? ('#' . $orgId);
}
$types = array();
foreach ($facet('type') as $type => $count) {
    $types[$type] = $type;
}
$categories = array();
foreach ($facet('category') as $category => $count) {
    $categories[$category] = $category;
}
$objects = array();
foreach ($facet('object') as $name => $count) {
    $objects[$name === '' ? '0' : $name] = $name === '' ? __('Standalone attribute') : $name;
}
$distributions = array();
foreach ($facet('distribution') as $level => $count) {
    $distributions[(string)$level] = DistributionLevel::get($level)['label'];
}

$children = array(
    array(
        'type' => 'search',
        'mode' => 'legacy',
        'name' => 'q',
        'button' => __('Search'),
        'chip_label' => __('Search'),
        'placeholder' => __('Filter by event, comment or UUID'),
    ),
);
if (!empty($orgs)) {
    $children[] = $dropdown('org', __('Reported by'), $orgs,
        'misp-icon misp-icon-organisation misp-simple');
}
if (!empty($types)) {
    $children[] = $dropdown('type', __('Type'), $types);
}
if (!empty($categories)) {
    $children[] = $dropdown('category', __('Category'), $categories);
}
if (!empty($objects)) {
    $children[] = $dropdown('object', __('Context'), $objects,
        'misp-icon misp-icon-object misp-simple');
}
$children[] = array(
    'type' => 'picker',
    'name' => 'tags',
    'label' => __('Tags'),
    'icon' => 'fas fa-tag',
    'source' => $baseurl . '/tags/pickerSearch',
    'separator' => '||',
    'resolved' => $filterLabels['tag'],
    'hint' => __('On the occurrence or its event.'),
    'group' => $group,
    'browser' => array(
        'kind' => 'tag',
        'scopes' => $baseurl . '/tags/pickerScopes',
        'tree' => $baseurl . '/tags/pickerTree',
    ),
);
$children[] = array(
    'type' => 'picker',
    'name' => 'galaxy',
    'label' => __('Galaxy'),
    'icon' => 'misp-icon misp-icon-galaxy misp-simple',
    'source' => $baseurl . '/galaxy_clusters/pickerSearch',
    'separator' => '||',
    'resolved' => $filterLabels['galaxy'],
    'hint' => __('Clusters on the occurrence or its event.'),
    'group' => $group,
    'browser' => array(
        'kind' => 'galaxy',
        'scopes' => $baseurl . '/galaxies/pickerScopes',
    ),
);
if (!empty($distributions)) {
    $children[] = $dropdown('distribution', __('Distribution'), $distributions);
}
$children[] = $dropdown('ids', __('IDS'), array('1' => __('Yes'), '2' => __('No')),
    'fas fa-shield-halved');

// The period, on the dates the old facet rail offered.
$timePresets = array(
    array('value' => '7d', 'label' => __('7d'), 'title' => __('last 7 days')),
    array('value' => '30d', 'label' => __('30d'), 'title' => __('last 30 days')),
    array('value' => '90d', 'label' => __('90d'), 'title' => __('last 90 days')),
    array('value' => '365d', 'label' => __('1y'), 'title' => __('last year')),
);
$children[] = array(
    'type' => 'time',
    'name' => 'time',
    'label' => __('Period'),
    'icon' => 'far fa-clock',
    'fields' => array(
        array('name' => 'changed', 'label' => __('Changed')),
        array('name' => 'created', 'label' => __('Created')),
        array('name' => 'seen', 'label' => __('Last seen')),
        array('name' => 'published', 'label' => __('Event published')),
    ),
    'presets' => $timePresets,
    'group' => $group,
);
$timeTitles = array_column($timePresets, 'title', 'value');
$timeLabel = function ($value) use ($timeTitles) {
    if (isset($timeTitles[$value])) {
        return $timeTitles[$value];
    }
    $range = explode('|', str_replace('||', '|', (string)$value), 2);
    if (count($range) === 2) {
        $from = $range[0] === '' || $range[0] === '0' ? '…' : substr($range[0], 0, 10);
        $to = $range[1] === '' ? '…' : substr($range[1], 0, 10);
        return $from . ' – ' . $to;
    }
    return sprintf(__('since %s'), $value);
};

// The toggles keep every other filter of the tab, as on the event's Attributes tab.
$tabState = new IndexFilterState($tabUrl, $named);
$currentDeleted = (int)($named['deleted'] ?? 0);
$currentProposal = (int)($named['proposal'] ?? 0);
$children[] = array(
    'type' => 'button',
    'url' => $tabState->url(array('proposal' => $currentProposal ? null : 1)),
    'class' => 'btn ' . ($currentProposal ? 'btn-warning' : 'btn-outline-warning'),
    'icon' => 'fas fa-comment-dots',
    'label' => __('Proposals'),
    'count' => (int)$stats['proposals'],
    'pressed' => (bool)$currentProposal,
);
$children[] = array(
    'type' => 'button',
    'url' => $tabState->url(array('deleted' => $currentDeleted ? null : 2)),
    'class' => 'btn ' . ($currentDeleted ? 'btn-danger' : 'btn-outline-danger'),
    'icon' => 'fas fa-trash',
    'label' => __('Deleted'),
    'count' => (int)$stats['deleted'],
    'pressed' => (bool)$currentDeleted,
);

$filterBar = array(
    'pull' => 'right',
    'children' => $children,
    'base_url' => $tabUrl,
    'view_switch' => false,
    'chips' => array(
        'labels' => array(
            'deleted' => __('Deleted'),
            'proposal' => __('Proposals'),
            'changed' => __('Changed'),
            'created' => __('Created'),
            'seen' => __('Last seen'),
            'published' => __('Event published'),
        ),
        'values' => array(
            'deleted' => array('1' => __('included'), '2' => __('only')),
            'proposal' => array('1' => __('only')),
            'changed' => $timeLabel,
            'created' => $timeLabel,
            'seen' => $timeLabel,
            'published' => $timeLabel,
        ),
    ),
    'mass_sighting' => true,
    'mass_graph' => $canGraph,
);

echo $this->element('genericElementsBS5/IndexTable/scaffold', array(
    'scaffold_data' => array(
        'data' => array(
            'data' => $rows,
            'table_class' => 'ai-table vp-occ-table',
            // No table/card switch here, so no card view to render.
            'card_view' => false,
            'primary_id_path' => 'Attribute.id',
            'row_class_callable' => function ($row) {
                return empty($row['Attribute']['deleted']) ? '' : 'attr-deleted';
            },
            'filter_bar' => $filterBar,
            'fields' => $this->ValueOccurrence->fields(array(
                'plan' => $profile['label_plan'],
                'select' => true,
                'sortable' => true,
                'feeds' => !empty($profile['feeds_shown']),
                'seen' => true,
            )),
        ),
    ),
    'item_url' => '/values',
));

if (!empty($profile['standalone_proposals'])) {
    echo $this->element('Values/View/value_standalone_proposals', array(
        'proposals' => $profile['standalone_proposals'],
    ));
}
