<?php
/**
 * Every placing this value has, with the source of each.
 *
 * The compact form picks the newest answer and says so in one line;
 * this is where the disagreement lives. Three databases placing one
 * address in three cities is a finding about the databases, and the
 * only place it can be read is a table with a source column.
 *
 * **The map asks nobody for anything.** It is the same baked Natural
 * Earth outline the compact widget draws, at the size a pane affords —
 * so it is the rendering on every instance rather than the one an
 * administrator has opted in to, and the table below it carries the
 * sources either way. A tile fetch would say no more about *where*
 * than this does; what it would add is a network round trip per
 * reader and a dependency on a server outside the instance.
 *
 * @var array $data
 */
$points = $data['points'];
$places = $data['places'];
?>
<div class="vp-rf vp-rf-geo">
    <?php if (!empty($points)): ?>
        <?= $this->element('Values/Renderers/world_outline') ?>
        <svg class="vp-rf-plot" viewBox="0 6 360 142"
             preserveAspectRatio="xMidYMid meet" aria-hidden="true">
            <rect x="0" y="6" width="360" height="142"
                  class="vp-rw-plot-bg"/>
            <use href="#vp-world" x="0" y="0" width="360" height="180"/>
            <?php foreach ($points as $point): ?>
                <?php
                $cx = round($point['lon'] + 180, 2);
                $cy = round(90 - $point['lat'], 2);
                ?>
                <circle cx="<?= h($cx) ?>" cy="<?= h($cy) ?>"
                        r="7" class="vp-rw-plot-halo"/>
                <circle cx="<?= h($cx) ?>" cy="<?= h($cy) ?>"
                        r="3" class="vp-rw-plot-dot"/>
            <?php endforeach; ?>
        </svg>
    <?php endif; ?>

    <?php if (!$data['agreed']): ?>
        <p class="vp-rf-note"><?= h(sprintf(
            __('%1$s sources place this value in %2$s different'
                . ' locations.'),
            count($data['sources']),
            count($places)
        )) ?></p>
    <?php endif; ?>

    <table class="table table-sm vp-rf-table">
        <thead>
            <tr>
                <th scope="col"><?= h(__('Place')) ?></th>
                <th scope="col"><?= h(__('Country')) ?></th>
                <th scope="col"><?= h(__('Coordinates')) ?></th>
                <th scope="col"><?= h(__('Source')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            /*
             * The points where there are any and the named places
             * otherwise: a module that resolved a country and no
             * coordinates has still said something, and a table built
             * from the points alone would drop it.
             */
            $rows = empty($points) ? $places : $points;
            ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['city'] === null
                        ? '—' : $row['city']) ?><?php
                        if (!empty($row['region'])): ?>
                        <span class="vp-rf-dim"><?=
                            h($row['region']) ?></span>
                    <?php endif; ?></td>
                    <td><?= h($row['country'] === null
                        ? '—' : $row['country']) ?><?php
                        if (!empty($row['code'])
                            && $row['code'] !== $row['country']): ?>
                        <span class="vp-rf-dim"><?=
                            h($row['code']) ?></span>
                    <?php endif; ?></td>
                    <td class="font-monospace"><?= isset($row['lat'])
                        ? h(sprintf('%.4f, %.4f', $row['lat'],
                            $row['lon']))
                        : '—' ?></td>
                    <td class="font-monospace vp-rf-dim"><?=
                        h((string)$row['module']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
