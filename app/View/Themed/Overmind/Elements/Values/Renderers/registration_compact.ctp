<?php
/**
 * How old the registration is, and who sold it.
 *
 * **The age is the headline and the date is the qualifier**, which is
 * the inverse of how the record states it. A domain registered eleven
 * days ago is the finding; *2026-09-06* is a fact a reader has to do
 * arithmetic on to reach it, and the arithmetic is what a triage
 * analyst has no seconds for.
 *
 * @var array $data
 */
$record = $data['headline'];
$age = $record['age_days'];
?>
<div class="vp-rw-in vp-rw-reg">
    <?php if ($age !== null): ?>
        <div class="vp-rw-metric">
            <span class="vp-rw-metric-n"><?=
                h(number_format($age)) ?></span>
            <span class="vp-rw-metric-l"><?= h(__n(
                'day old', 'days old', $age
            )) ?></span>
        </div>
        <div class="vp-rw-sub"><?= h(date('Y-m-d',
            $record['created'])) ?></div>
    <?php else: ?>
        <div class="vp-rw-head vp-rw-quiet"><?=
            h(__('registered')) ?></div>
    <?php endif; ?>
    <?php if ($record['registrar'] !== null): ?>
        <div class="vp-rw-note" title="<?=
            h($record['registrar']) ?>"><?= h($record['registrar']) ?></div>
    <?php endif; ?>
    <?php if ($record['expires_in_days'] !== null
        && $record['expires_in_days'] < 30): ?>
        <?php
        /*
         * An expiry inside a month is a finding on its own: a domain
         * about to lapse is about to belong to somebody else.
         */
        ?>
        <div class="vp-rw-note vp-rw-split"><?= h(
            $record['expires_in_days'] < 0
                ? __('expired')
                : sprintf(__('expires in %s days'),
                    $record['expires_in_days'])
        ) ?></div>
    <?php endif; ?>
</div>
