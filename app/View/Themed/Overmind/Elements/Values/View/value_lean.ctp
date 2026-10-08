<?php
/**
 * The lean pill: a coloured dot, the words, and the quality when there
 * is one.
 *
 * The markup form of a lean. The colour itself comes from `ValueLean`,
 * which the tab bar and the Assessment tab read too — they each need it
 * in a different form, and three copies of the same table is three
 * chances for the page to contradict itself about what a record says.
 *
 * @var string $lean   threat | benign | contested | none
 * @var int|null $quality The quality. Null where nothing computed one;
 *                        **capped at 100 and not floored at 0**,
 *                        because the ledger sums to it exactly and a
 *                        record with more absences than substance nets
 *                        negative. Not a record disputing its own
 *                        lean — that evidence sums into `lean_weight`
 *                        since `review-2026-09-13.md` §D1 and cannot
 *                        reach this number. This element prints it; a
 *                        caller drawing a bar off it clamps its own
 *                        width (`value_verdict.ctp`)
 * @var string $size   'lg' for the headline, otherwise inline
 */
App::uses('ValueLean', 'Tools/ValueProfile');

$quality = $quality ?? null;
$size = $size ?? '';

$colour = ValueLean::colour($lean);

/*
 * A lean that refuses to name a state is drawn quietly. `contested` and
 * `none` are the absence of an answer, and a solid chip in the same
 * weight as *Asserted threat* claims a certainty the record does not
 * have — which is what `isDefinite()` has always been for.
 */
$quiet = ValueLean::isDefinite($lean) ? '' : ' vp-disposition-quiet';
?>
<span class="vp-disposition<?= $size === 'lg' ? ' vp-disposition-lg' : '' ?><?= $quiet ?>"
      style="--vp-disposition-color: <?= h($colour) ?>;">
    <span class="vp-disposition-dot"></span>
    <span class="vp-disposition-label"><?= h(ValueLean::label($lean)) ?></span>
    <?php if ($quality !== null): ?>
        <span class="vp-disposition-score"><?= h($quality) ?></span>
    <?php endif; ?>
</span>
