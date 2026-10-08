<?php
/**
 * The hover card's enrichment strip: what the modules said, one chip
 * each.
 *
 * **Fetched by the card, never with it.** Knowing what a reader could
 * ask costs an outbound call to the modules service
 * (`enrichmentCatalogue`), and the card's whole cost argument is that a
 * hover is worth one assessment and nothing more. So the card paints
 * without this, asks for it, and grows once — and modules that have to
 * be run fill in after that, which is why each chip is a name with a
 * slot rather than a finished string.
 *
 * **The same firing contract as the Overview panel.** The plan travels
 * on the element as `data-vp-eb-fire`, five go at a time, and each is
 * `mode=auto` at the endpoint that decides the gate and the reuse
 * window again when it lands. What differs is `shape=chip`: the panel
 * wants every chip a module returned and the card wants the headline.
 *
 * When, never who: naming the analyst who ran a module would tell the
 * organisation which colleague is looking at which value.
 *
 * @var array $valueProfile `panel`, from `ValueProfile::forEnrichmentPanel`
 * @var string $valueB64
 */
$panel = $valueProfile['panel'];
if (empty($panel['present']) || empty($panel['modules'])) {
    /*
     * Nothing known and nothing askable. The card renders no section at
     * all rather than an empty one — a strip reading *no enrichment* on
     * every value of every instance that never enriched anything is the
     * permanently empty row the Overview's own guard exists to refuse.
     */
    return;
}

$baseurl = isset($baseurl) ? $baseurl : Configure::read('MISP.baseurl');
$token = isset($this->request->params['_Token']['key'])
    ? $this->request->params['_Token']['key']
    : '';

/*
 * Four chips, and the rest counted. The strip held one line at 360px
 * until 2026-09-15, when the height allowed it was doubled; two rows
 * of two is what that buys, and `38-hover-card.md` §13 carries the
 * measurement.
 *
 * The cap is the firing set as well as the drawn set — see `$names`
 * below — so doubling it doubles the modules a hover may ask for. That
 * is the real price of the second line, and it is why the cap moved
 * with the height rather than the height alone.
 */
$cap = 4;
$shown = array_slice($panel['modules'], 0, $cap);
$more = max(0, count($panel['modules']) - $cap);

/*
 * Only the modules still on the strip may be fired. A plan naming one
 * the cap dropped would send a request whose answer has nowhere to
 * land, and the lane would spend itself writing into nothing.
 */
$names = array();
foreach ($shown as $entry) {
    $names[$entry['module']] = true;
}
$fire = array();
foreach ($panel['fire'] as $one) {
    if (isset($names[$one['module']])) {
        $fire[] = $one;
    }
}
?>
<div class="vp-hc-sec vp-hc-enr"
     data-vp-ebadge
     data-vp-eb-shape="chip"
     data-vp-eb-token="<?= h($token) ?>"
     data-vp-eb-fire="<?= h(json_encode($fire)) ?>"
     data-vp-eb-max="5"
     data-vp-eb-url="<?= h($baseurl . '/values/viewEnrichmentBadge/'
        . $valueB64) ?>">
    <div class="vp-hc-enr-strip">
        <span class="vp-hc-enr-k"><?= h(__('Enrichment')) ?></span>
        <span class="vp-hc-enr-flow">
        <?php foreach ($shown as $entry): ?>
            <span class="vp-hc-echip"
                  data-vp-eb-row="<?= h($entry['module']) ?>"
                  title="<?= h($entry['module']) ?>">
                <span class="vp-hc-echip-k"><?= h($entry['module']) ?></span>
                <span class="vp-hc-echip-slot" data-vp-eb-slot>
                    <?php if ($entry['pending'] === 'fire'): ?>
                        <i class="fas fa-circle-notch fa-spin"
                           aria-hidden="true"></i>
                        <span class="visually-hidden"><?=
                            h(__('asking')) ?></span>
                    <?php elseif ($entry['pending'] === 'in_flight'): ?>
                        <?php
                        /*
                         * Somebody else's request is about to write
                         * this answer, so nothing is sent: asking now
                         * would start a second query rather than wait
                         * for the first.
                         */
                        ?>
                        <span class="vp-hce-v vp-hce-wait"><?=
                            h(__('being asked')) ?></span>
                    <?php else: ?>
                        <?= $this->element(
                            'Values/View/value_enrichment_chip',
                            array('badge' => $entry)
                        ) ?>
                    <?php endif; ?>
                </span>
            </span>
        <?php endforeach; ?>
        </span>
        <?php if ($more > 0): ?>
            <span class="vp-hc-echip vp-hc-echip-q"
                  title="<?= h(sprintf(
                      __('%s more module on the Enrichment tab'),
                      $more
                  )) ?>">+<?= h($more) ?></span>
        <?php endif; ?>
    </div>
</div>
