<?php
/**
 * `/values/index` — the way in to the Value Intelligence.
 *
 * The main column holds the box and what it resolved; the rail beside it
 * holds the analyst profile and the instance figures. A worklist moves its
 * own review cards to the top of the rail (`value-index.js`), and the
 * instance figures step aside while it is there.
 *
 * The region under the box has one occupant at a time — the invitation,
 * an answer or a worklist — so the page's script can refill it from
 * `ValuesController::triage()` without the paste above it moving.
 *
 * @var array|null $resolution What `ValuesController::resolve()` made
 *                             of the box, or null on a plain load
 * @var array|null $triage The rows a pasted list became, or null
 * @var array $recent The values this reader last opened, newest first
 * @var array|null $inForce The Analyst Profile deciding this reader's
 *                          assessments, or null when none is
 * @var array $tiles As `ValueIntelligence::forTiles()`
 */
echo $this->element('genericElements/assetLoader', array(
    'css' => array('value-palette', 'value-index', 'value-index-search'),
    'js' => array('value-index', 'value-index-search'),
));

$this->set('headerTitle', __('Values'));
$this->set('headerBreadcrumb', __('Values'));
$this->set('headerDescription', __(
    'Look up a value to see what MISP knows about it, or paste a list'
    . ' to triage it.'
));
?>
<div class="vi-page">
    <div class="row g-3">
        <div class="col-lg-9 vi-main">
            <?= $this->element('Values/Index/prompt') ?>
            <div class="vi-out" data-vi-out>
<?php if ($triage !== null): ?>
                <?= $this->element('Values/Index/worklist', array(
                    'triage' => $triage,
                )) ?>
<?php elseif ($resolution === null): ?>
                <?= $this->element('Values/Index/invite') ?>
<?php else: ?>
                <?= $this->element('Values/Index/answer', array(
                    'resolution' => $resolution,
                )) ?>
<?php endif; ?>
            </div>
            <?php // The empty region again, for the clear control to put back. ?>
            <template data-vi-invite><?= $this->element('Values/Index/invite') ?></template>
            <?= $this->element('Values/Index/search') ?>
            <?= $this->element('Values/Index/recent', array(
                'recent' => $recent,
            )) ?>
        </div>
        <aside class="col-lg-3 vi-side" data-vi-side
               aria-label="<?= h(__('About these values')) ?>">
            <div class="vi-side-work" data-vi-side-work></div>
            <?= $this->element('Values/Index/conditions', array(
                'inForce' => $inForce,
            )) ?>
            <?= $this->element('Values/Index/instance', array(
                'tiles' => $tiles,
            )) ?>
        </aside>
    </div>
</div>
