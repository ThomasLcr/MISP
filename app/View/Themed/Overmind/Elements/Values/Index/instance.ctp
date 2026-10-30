<?php
/**
 * The rail's instance card: standing facts about the instance and the
 * reader's organisation, one line each, with the longer explanation on
 * hover.
 *
 * **Enabled warninglists**, because that is the set a value is matched
 * against. **The enrichment cache is your organisation's**: the store is
 * org-scoped, and it is a count and a window only, since a list of what
 * an organisation recently enriched is a list of what it is
 * investigating.
 *
 * @var array $tiles As `ValueIntelligence::forTiles()`
 */
$dictionary = $tiles['dictionary'];
$over = $tiles['overCorrelating'];
$warninglists = (int)$tiles['warninglists'];
$store = $tiles['store'];
$hours = (int)$store['max_age_hours'];
$floor = '≥&#8239;';

$facts = array();
if ($dictionary === null && $tiles['dictionaryPending']) {
    $facts[] = array(
        'label' => h(__('Distinct values')),
        'value' => '<span class="vi-fact__none">' . h(__('Counting…')) . '</span>',
        'title' => __('The first count runs in the background and shows here once it is done.'),
    );
}
if ($dictionary !== null) {
    $facts[] = array(
        'label' => h(__('Distinct values')),
        'value' => ($dictionary['total_floor'] ? $floor : '')
            . h(number_format($dictionary['total'])),
        'title' => __('Each value is stored once, however many attributes carry it.'),
    );
    $recent = $dictionary['recent'];
    if ($recent['since'] === null) {
        $window = __('in the last 7 days');
    } elseif (gmdate('Y-m-d', $recent['since']) === gmdate('Y-m-d')) {
        $window = __('today');
    } else {
        $window = __('since %s', gmdate('j M', $recent['since']));
    }
    if ($recent['values'] > 0) {
        $title = __n('From %s attribute added.', 'From %s attributes added.',
            $recent['attributes'], number_format($recent['attributes']));
    } elseif ($recent['attributes'] > 0) {
        $title = __n('%s attribute added, carrying a value already stored.',
            '%s attributes added, all carrying values already stored.',
            $recent['attributes'], number_format($recent['attributes']));
    } else {
        $title = __('No attributes added.');
    }
    $facts[] = array(
        'label' => h(__('New values')) . ' <span class="vi-fact__sub">'
            . h($window) . '</span>',
        'value' => $recent['values'] > 0
            ? ($recent['floor'] ? $floor : '') . '+' . h(number_format($recent['values']))
            : '<span class="vi-fact__none">' . h(__('None')) . '</span>',
        'title' => $title,
    );
}
if ($over !== null) {
    $facts[] = array(
        'label' => h(__('Too common to correlate')),
        'value' => ($over['count'] > 0
                ? h(number_format($over['count']))
                : '<span class="vi-fact__none">' . h(__('None')) . '</span>')
            . ($over['review'] && $over['count'] > 0
                ? ' ' . $this->Html->link(__('Review'), array(
                    'controller' => 'correlations',
                    'action' => 'overCorrelations',
                ), array('class' => 'vi-fact__link'))
                : ''),
        'title' => __('Values matching more than %s attributes are no longer correlated.',
            number_format($over['limit'])),
    );
}
$facts[] = array(
    'label' => h(__('Warninglists')),
    'value' => ($warninglists > 0
            ? h(__('%s enabled', number_format($warninglists)))
            : '<span class="vi-fact__warn"><i class="fas fa-triangle-exclamation"'
                . ' aria-hidden="true"></i> ' . h(__('None enabled')) . '</span>')
        . (!empty($isSiteAdmin)
            ? ' ' . $this->Html->link(__('Manage'), array(
                'controller' => 'warninglists',
                'action' => 'index',
            ), array('class' => 'vi-fact__link'))
            : ''),
    'title' => $warninglists > 0
        ? __('Every value is checked against the enabled warninglists.')
        : __('No value will be flagged as a known false positive.'),
);
$facts[] = array(
    'label' => h(__('Enrichment cache')),
    'value' => ($store['count'] > 0
            ? h(number_format($store['count']))
            : '<span class="vi-fact__none">' . h(__('None yet')) . '</span>')
        . ' <span class="vi-fact__sub">· ' . h(__n('kept %s hour', 'kept %s hours',
            $hours, number_format($hours))) . '</span>',
    'title' => __n(
        'Results cached for your organisation. Each is kept %s hour before a module is queried again.',
        'Results cached for your organisation. Each is kept %s hours before a module is queried again.',
        $hours,
        number_format($hours)
    ),
);
?>
<section class="card shadow-sm vi-rcard vi-side-instance" aria-labelledby="vi-rcard-instance">
    <header class="vi-rcard-h">
        <span class="vi-rcard-ico" aria-hidden="true"><i class="fas fa-server"></i></span>
        <h2 class="vi-rcard-t" id="vi-rcard-instance"><?= h(__('This instance')) ?></h2>
    </header>
    <dl class="vi-facts">
<?php foreach ($facts as $fact): ?>
        <div class="vi-fact" title="<?= h($fact['title']) ?>">
            <dt><?= $fact['label'] ?></dt>
            <dd><?= $fact['value'] ?></dd>
        </div>
<?php endforeach; ?>
    </dl>
</section>
