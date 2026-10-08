<?php
$event = $data['Event'] ?? [];
$eventId = (int)($event['id'] ?? 0);
$report = $data['EventReport'] ?? null;
$others = $data['OtherEventReports'] ?? [];
$reportCount = (int)($report_count ?? (empty($report) ? 0 : 1 + count($others)));
$canAddReport = $this->Acl->canModifyEvent($data);
$addReportUrl = $baseurl . '/event_reports/add/' . $eventId;
$addObjectUrl = $baseurl . '/objects/add/' . $eventId;
$hasObjects = (int)($object_count ?? 0) > 0;
$structureEmpty = !($has_object_references ?? true);
$graphFoldable = !empty($report);
$graphCompact = $structureEmpty && $graphFoldable;
$graphHint = $hasObjects
    ? [
        'title' => __('No objects are linked yet'),
        'text' => __('References say how the objects relate: which file dropped which, where a domain resolved. Draw them in the Pivot Explorer.'),
        'reader' => __('No object in this event references another.'),
    ]
    : [
        'title' => __('No objects yet'),
        'text' => __('Gather related attributes into objects, then link the objects to show how they relate.'),
        'reader' => __('This event has no objects.'),
    ];
$graphHintAction = function () use ($hasObjects, $addObjectUrl) {
    if ($hasObjects) {
        return '<button type="button" class="btn btn-sm btn-correlation" data-eo-tab="pivot-explorer">'
            . '<i class="fas fa-link me-1"></i>' . __('Link objects') . '</button>';
    }
    return sprintf(
        '<a href="%s" class="btn btn-sm btn-correlation" onclick="event.preventDefault(); openModal(\'%s\');">'
            . '<i class="fas fa-plus me-1"></i>%s</a>',
        h($addObjectUrl), h($addObjectUrl), __('Add an object')
    );
};
$reportEventId = (int)($report['event_id'] ?? $eventId);
$reportOrg = $extensionEvents[$reportEventId]['Orgc']['name'] ?? ($data['Orgc']['name'] ?? '');
$reportOrigin = function ($originId) {
    return $this->element('Events/View/extension_origin', [
        'event_id' => (int)$originId,
        'compact' => true,
        'only_foreign' => true,
    ]);
};
?>
<div class="row g-3 mb-3 eo-row">

    <div class="col-12<?= $graphCompact ? '' : ' col-xl-5' ?> d-flex" data-eo-col="report">
        <div class="card shadow-sm eo-card w-100" id="eo-report-card"
             <?php if (!empty($report)): ?>
             data-eo-report-id="<?= (int)$report['id'] ?>"
             data-eo-event-id="<?= $reportEventId ?>"
             data-eo-report-content="<?= h($report['content'] ?? '') ?>"
             <?php endif; ?>>
            <div class="eo-card-head">
                <div class="misp-icon-tile eo-tile" style="--tile:var(--bs-report);--tile-bg:color-mix(in srgb, var(--bs-report) 12%, transparent);">
                    <i class="misp-icon misp-icon-report misp-simple"></i>
                </div>
                <div class="min-w-0 me-auto">
                    <?php if (!empty($report)): ?>
                        <div class="eo-card-title text-truncate"><?= h($report['name']) ?></div>
                        <div class="eo-card-sub text-truncate">
                            <?= $reportOrigin($reportEventId) ?>
                            <?= h($reportOrg) ?> · <?= __('modified %s', $this->Time->time($report['timestamp'])) ?>
                        </div>
                    <?php else: ?>
                        <div class="eo-card-title"><?= __('Report') ?></div>
                    <?php endif; ?>
                </div>
                <div class="eo-figure"><b><?= $reportCount ?></b> <?= h(__n('report', 'reports', $reportCount)) ?></div>
            </div>

            <?php if (!empty($report)): ?>
                <div class="eo-report-body">
                    <div class="eo-report-clamp">
                        <div class="markdown-preview-body" data-eo-report-preview>
                            <div class="text-center text-muted py-4"><div class="misp-loader misp-loader-sm" role="status"></div></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($others)): ?>
                    <ul class="eo-other-reports">
                        <?php foreach ($others as $other): ?>
                            <li>
                                <a href="<?= h($baseurl . '/eventReports/view/' . (int)$other['id']) ?>" class="text-truncate">
                                    <i class="misp-icon misp-icon-report misp-simple"></i><?= h($other['name']) ?>
                                </a>
                                <span class="d-inline-flex align-items-center gap-2 flex-shrink-0">
                                    <?= $reportOrigin($other['event_id'] ?? $eventId) ?>
                                    <span class="eo-muted"><?= $this->Time->time($other['timestamp']) ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="eo-card-foot">
                    <button type="button" class="btn btn-sm btn-report" data-eo-report-open>
                        <i class="fas fa-book-open me-1"></i><?= __('Read in full') ?>
                    </button>
                </div>
            <?php else: ?>
                <?php if ($canAddReport): ?>
                    <div class="eo-prompt" style="--eo-prompt:var(--bs-report);">
                        <div class="eo-prompt-text">
                            <div class="eo-prompt-title"><?= __('No report yet') ?></div>
                            <p><?= __('A report tells the story behind the indicators: what happened, how it was found and what to do about it.') ?></p>
                        </div>
                        <a href="<?= h($addReportUrl) ?>" class="btn btn-sm btn-report"
                           onclick="event.preventDefault(); openModal('<?= h($addReportUrl) ?>');">
                            <i class="fas fa-pen me-1"></i><?= __('Write a report') ?>
                        </a>
                    </div>
                <?php else: ?>
                    <p class="eo-empty"><?= __('This event has no report.') ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12<?= $graphCompact ? '' : ' col-xl-7' ?> d-flex" data-eo-col="graph">
        <?php if ($graphFoldable && $canAddReport): ?>
            <div class="eo-prompt eo-graph-strip w-100<?= $graphCompact ? '' : ' d-none' ?>" style="--eo-prompt:var(--bs-correlation);" data-eo-graph-strip>
                <div class="misp-icon-tile eo-tile eo-tile-sm" style="--tile:var(--bs-correlation);--tile-bg:color-mix(in srgb, var(--bs-correlation) 12%, transparent);">
                    <i class="fas fa-circle-nodes"></i>
                </div>
                <div class="eo-prompt-text">
                    <div class="eo-prompt-title"><?= h($graphHint['title']) ?></div>
                    <p><?= h($graphHint['text']) ?></p>
                </div>
                <?= $graphHintAction() ?>
            </div>
        <?php elseif ($graphFoldable): ?>
            <div class="card shadow-sm eo-card eo-graph-strip w-100<?= $graphCompact ? '' : ' d-none' ?>" data-eo-graph-strip>
                <div class="misp-icon-tile eo-tile eo-tile-sm" style="--tile:var(--bs-correlation);--tile-bg:color-mix(in srgb, var(--bs-correlation) 12%, transparent);">
                    <i class="fas fa-circle-nodes"></i>
                </div>
                <span class="eo-card-title"><?= __('Event graph') ?></span>
                <span class="eo-muted"><?= h($graphHint['reader']) ?></span>
            </div>
        <?php endif; ?>
        <div class="card shadow-sm eo-card w-100<?= $graphCompact ? ' d-none' : '' ?>" id="eo-graph-card"
             data-eo-structure-empty="<?= $structureEmpty ? '1' : '0' ?>"
             data-eo-graph-foldable="<?= $graphFoldable ? '1' : '0' ?>"
             data-eo-graph-url="<?= h($baseurl . '/events/viewEventOverviewGraph/' . $eventId . ($extensionSuffix ?? '') . '.json') ?>"
             data-eo-event-id="<?= $eventId ?>"
             data-eo-event-uuid="<?= h($event['uuid'] ?? '') ?>"
             data-eo-text-summary="<?= h(__('%s linked objects and attributes — too many to draw here.')) ?>"
             data-eo-text-failed="<?= h(__('The graph could not be drawn.')) ?>"
             data-eo-text-counts="<?= h(__('%1$s objects, %2$s references')) ?>"
             data-eo-text-saved="<?= h(__('Saved graph: %s')) ?>"
             data-eo-text-saved-count="<?= h(__('%s saved graphs')) ?>">
            <div class="eo-card-head">
                <div class="misp-icon-tile eo-tile" style="--tile:var(--bs-correlation);--tile-bg:color-mix(in srgb, var(--bs-correlation) 12%, transparent);">
                    <i class="fas fa-circle-nodes"></i>
                </div>
                <div class="min-w-0 me-auto">
                    <div class="eo-card-title"><?= __('Event graph') ?></div>
                    <div class="eo-card-sub text-truncate" data-eo-graph-sub>&nbsp;</div>
                </div>
                <div class="eo-switch" role="group" aria-label="<?= h(__('Graph source')) ?>">
                    <button type="button" data-eo-graph-mode="saved" disabled
                            title="<?= h(__('No saved graph on this event')) ?>"
                            data-eo-title-ready="<?= h(__('Show the saved graph')) ?>">
                        <i class="fas fa-bookmark"></i><?= __('Saved graph') ?>
                    </button>
                    <button type="button" class="active" data-eo-graph-mode="structure"
                            title="<?= h(__('Show how the objects reference each other')) ?>">
                        <i class="fas fa-sitemap"></i><?= __('Structure') ?>
                    </button>
                </div>
            </div>
            <div class="eo-graph-stage">
                <div class="eo-graph-loader" data-eo-graph-loader>
                    <div class="misp-loader misp-loader-sm" role="status"></div>
                </div>
                <div class="eo-graph-canvas" data-eo-graph-canvas="structure"></div>
                <div class="eo-graph-canvas d-none" data-eo-graph-canvas="saved"></div>
                <div class="eo-graph-message d-none" data-eo-graph-message></div>
                <?php if ($canAddReport): ?>
                    <div class="eo-prompt d-none" style="--eo-prompt:var(--bs-correlation);" data-eo-graph-empty>
                        <div class="eo-prompt-text">
                            <div class="eo-prompt-title"><?= h($graphHint['title']) ?></div>
                            <p><?= h($graphHint['text']) ?></p>
                        </div>
                        <?= $graphHintAction() ?>
                    </div>
                <?php else: ?>
                    <p class="eo-empty d-none" data-eo-graph-empty><?= h($graphHint['reader']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($report)): ?>
<div class="modal fade" id="eo-report-modal" tabindex="-1" aria-labelledby="eo-report-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h5 class="modal-title text-truncate" id="eo-report-modal-title"><?= h($report['name']) ?></h5>
                    <div class="eo-card-sub"><?= h($data['Orgc']['name'] ?? '') ?> · <?= __('modified %s', $this->Time->time($report['timestamp'])) ?></div>
                </div>
                <a class="btn btn-sm btn-outline-secondary ms-auto me-2" href="<?= h($baseurl . '/eventReports/view/' . (int)$report['id']) ?>"><?= __('Open report page') ?></a>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(__('Close')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="markdown-preview-body eo-report-full" data-eo-report-full></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
