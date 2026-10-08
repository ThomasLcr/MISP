<?php
$event    = $data['Event']      ?? [];
$org      = $data['Org']        ?? [];
$orgc     = $data['Orgc']       ?? [];
$sg       = $data['SharingGroup'] ?? [];
$eventTags= $data['EventTag']  ?? [];
$user     = $data['User']       ?? [];

$analysisStops = [
    ['value' => 0, 'title' => __('Initial'),   'tone' => 'var(--misp-tone-blue-solid, #0d6efd)', 'sub' => __('Raw intelligence')],
    ['value' => 1, 'title' => __('Ongoing'),   'tone' => 'var(--misp-tone-orange-solid, #fd7e14)', 'sub' => __('Under investigation')],
    ['value' => 2, 'title' => __('Completed'), 'tone' => 'var(--misp-tone-green-solid, #198754)', 'sub' => __('Verified & closed')],
];

/* Lowest risk first — the ids themselves run the other way (1 is High). */
$threatStops = [
    ['value' => 4, 'title' => __('Undefined'), 'tone' => 'var(--misp-tone-gray-solid, #41464b)', 'sub' => __('No risk')],
    ['value' => 3, 'title' => __('Low'),       'tone' => 'var(--misp-tone-yellow-solid, #ffc107)', 'sub' => __('Opportunistic')],
    ['value' => 2, 'title' => __('Medium'),    'tone' => 'var(--misp-tone-orange-solid, #fd7e14)', 'sub' => __('Targeted campaign')],
    ['value' => 1, 'title' => __('High'),      'tone' => 'var(--misp-tone-red-solid, #dc3545)', 'sub' => __('Active exploitation')],
];

$analysisLevel  = (int)($event['analysis']        ?? 0);
$threatLevelId  = (int)($event['threat_level_id'] ?? 4);
$distribution   = (int)($event['distribution']    ?? 0);
$isPublished    = !empty($event['published']);
$disableCorrel  = !empty($event['disable_correlation']);

$descParts = [];
if (!empty($event['date'])) {
    $descParts[] = '<span>'
        . '<i class="fas fa-calendar-day me-1 opacity-50"></i>'
        . h($event['date'])
        . '</span>';
}
if (!empty($event['timestamp'])) {
    $descParts[] = '<span>'
        . '<i class="fas fa-edit me-1 opacity-50"></i>'
        . $this->Time->time($event['timestamp'])
        . '</span>';
}
$headerDescription = '<span class="d-inline-flex gap-3 flex-wrap">'
    . implode('', $descParts)
    . '</span>';
$this->set('headerDescription', $headerDescription);
?>

<div class="card mb-3 shadow-sm" data-tour="event-general">
    <div class="card-body">

        <!-- ── EVENT REPORT PREVIEW ──────────────────────────── -->
        <?php
        $erReportData  = $data['EventReport'] ?? [];
        $erContent     = $erReportData['content'] ?? '';
        $erHasReport   = !empty($erReportData);
        $erCardId      = 'er-general-card';
        $erBodyId      = 'er-general-body';
        $erOverlayId   = 'er-general-overlay';
        $erMaxH        = '300px';
        $erCanAddReport = $this->Acl->canModifyEvent($data);
        ?>
        <div class="mb-3">
            <div class="rounded-3 border p-3 h-100 ov-mini-card"
                 <?php if ($erHasReport): ?>
                 data-er-preview="<?= h($erCardId) ?>"
                 data-er-preview-overlay="<?= h($erOverlayId) ?>"
                 data-er-preview-collapsed="<?= h($erMaxH) ?>"
                 <?php else: ?>
                 data-center-on-click
                 <?php endif; ?>>
                <div class="text-muted small text-uppercase fw-bold mb-2">
                        <i class="misp-icon misp-icon-report misp-hexagone me-1"></i>
                        <?= __('Report') ?>
                </div>
                <?php if ($erHasReport): ?>
                    <div id="<?= h($erCardId) ?>"
                        style="max-height:<?= $erMaxH ?>;overflow:hidden;">
                        <div id="<?= h($erBodyId) ?>" class="markdown-preview-body"></div>
                    </div>
                    <div id="<?= h($erOverlayId) ?>" class="er-preview-overlay" style="display:none;">
                        <div class="er-preview-gradient"></div>
                    </div>
                <?php else: ?>
                    <?php $erAddUrl = h($baseurl . '/event_reports/add/' . ($data['Event']['id'] ?? '')); ?>
                    <div class="ov-empty-slot d-flex align-items-center gap-3 flex-wrap">
                        <span class="ov-empty-slot-glyph">
                            <i class="misp-icon misp-icon-report misp-hexagone"></i>
                        </span>
                        <div class="me-auto">
                            <div class="fw-semibold small lh-sm">
                                <?= __('No report yet') ?>
                            </div>
                            <div class="text-muted lh-sm" style="font-size:.75rem;">
                                <?= __('A report is where this event is told as a story, in markdown.') ?>
                            </div>
                        </div>
                        <?php if ($erCanAddReport): ?>
                            <a class="btn btn-sm btn-outline-report flex-shrink-0 d-inline-flex align-items-center gap-1"
                               href="<?= $erAddUrl ?>"
                               onclick="event.preventDefault(); openModal('<?= $erAddUrl ?>');">
                                <i class="fas fa-plus"></i>
                                <?= __('Create the first report') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── PRIMARY: Identifiers + Creator + Distribution + Publication ── -->
        <div class="row g-3 mb-3">

            <!-- ID + UUID -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded-3 border p-3 h-100 ov-mini-card">
                    <div class="text-muted small text-uppercase fw-bold mb-2">
                        <i class="fas fa-fingerprint me-1"></i>
                        <?= __('Identifiers') ?>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="text-muted small fw-bold">ID</span>
                            <span class="bg-light border rounded px-2 py-1 fw-semibold small font-monospace">
                                #<?= h($event['id'] ?? '') ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="text-muted small fw-bold flex-shrink-0">UUID</span>
                            <div class="d-inline-flex align-items-center gap-1 bg-light border rounded px-2 py-1 min-w-0">
                                <span class="font-monospace small text-truncate min-w-0"><?= h($event['uuid'] ?? '') ?></span>
                                <button
                                    class="text-muted border-0 bg-transparent p-0 ms-1 flex-shrink-0"
                                    onclick="copyToClipboard(this, '<?= h($event['uuid'] ?? '') ?>')"
                                    data-bs-toggle="tooltip"
                                    title="<?= __('Copy UUID') ?>"
                                    aria-label="<?= __('Copy UUID') ?>">
                                    <i class="fas fa-copy" style="font-size:0.75rem;"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Created by -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded-3 border p-3 h-100 d-flex flex-column ov-mini-card">
                    <div class="text-muted small text-uppercase fw-bold mb-2">
                        <span class="misp-icon misp-icon-user1 misp-hexagone"></span>
                        <?= __('Created by') ?>
                    </div>
                    <div class="d-flex flex-column justify-content-between flex-grow-1">
                        <div class="d-inline-flex align-items-center gap-2 py-1">
                            <?php $logo = $this->OrgImg->getOrgLogoV2($orgc, 24); ?>
                            <?= $logo !== '' ? $logo : '<i class="misp-icon misp-icon-organisation misp-simple text-muted"></i>' ?>
                            <a href="<?= h($baseurl . '/organisations/view/' . $orgc['id']) ?>"
                               class="text-decoration-none fw-semibold text-truncate min-w-0"><?= h($orgc['name'] ?? '') ?>
                            </a>
                        </div>
                        <?php $email = h($user['email'] ?? ''); ?>
                        <?php if ($email !== ''): ?>
                            <div class="d-flex align-items-center gap-2 text-muted small py-1">
                                <span class="text-truncate min-w-0">
                                    <i class="misp-icon misp-icon-user1 misp-simple"></i><?= $email ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- DISTRIBUTION + SHARING GROUP -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded-3 border p-3 h-100 d-flex flex-column ov-mini-card">
                    <div class="text-muted small text-uppercase fw-bold mb-2">
                        <i class="fas fa-broadcast-tower me-1"></i>
                        <?= __('Distribution') ?>
                    </div>
                    <div class="d-flex flex-column justify-content-between align-items-start flex-grow-1">
                        <div class = "py-1">
                        <?= $this->element('genericElementsBS5/Badges/distribution', [
                            'distribution' => $distribution,
                            'full'         => true
                        ]); ?>
                        </div>
                        <?php if ($distribution === 4 && !empty($sg)): ?>
                            <div class = "py-1">
                                <a href="<?= h($baseurl . '/sharingGroups/view/' . ($sg['id'] ?? '')) ?>"
                                class="d-inline-flex align-items-center gap-1 text-decoration-none fw-semibold mw-100">
                                    <span class="misp-icon misp-icon-sharing-group misp-hexagone text-accent"></span>
                                    <span class="text-truncate min-w-0"><?= h($sg['name'] ?? '') ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PUBLICATION: status + dates -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded-3 border p-3 h-100 d-flex flex-column ov-mini-card">
                    <div class="text-muted small text-uppercase fw-bold mb-2">
                        <i class="fas fa-paper-plane me-1"></i>
                        <?= __('Publication') ?>
                    </div>
                    <div class="d-flex flex-column justify-content-between align-items-start flex-grow-1">
                        <?= $this->element('genericElementsBS5/Badges/boolean', [
                            'boolean'    => $isPublished,
                            'full'       => true,
                            'true'       => __('Published'),
                            'false'      => __('Unpublished'),
                            'trueColor'  => 'success',
                            'falseColor' => 'warning',
                            'trueIcon'   => 'fa-upload',
                            'falseIcon'  => 'fa-warning',
                        ]); ?>
                        <?php if ($isPublished && !empty($event['first_publication'])): ?>
                            <div class="d-flex align-items-center gap-1 text-muted small">
                                <i class="fas fa-flag fa-fw"></i>
                                <span class="fw-medium"><?= __('First:') ?></span>
                                <?= $this->Time->time($event['first_publication']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($isPublished && !empty($event['publish_timestamp'])): ?>
                            <div class="d-flex align-items-center gap-1 text-muted small">
                                <i class="fas fa-history fa-fw"></i>
                                <span class="fw-medium"><?= __('Last:') ?></span>
                                <?= $this->Time->time($event['publish_timestamp']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── SECONDARY ─────────────────────────────────────── -->

        <!-- ANALYSIS + THREAT LEVEL -->
        <div class="row g-4 mb-4">

            <div class="col-12 col-md-6">
                <div class="text-muted small text-uppercase fw-bold mb-2">
                    <?= __('Analysis') ?>
                </div>
                <?= $this->element('genericElementsBS5/Forms/choice_slider', [
                    'field'    => 'analysis',
                    'value'    => $analysisLevel,
                    'options'  => $analysisStops,
                    'readonly' => true,
                ]) ?>
            </div>

            <div class="col-12 col-md-6">
                <div class="text-muted small text-uppercase fw-bold mb-2">
                    <?= __('Threat Level') ?>
                </div>
                <?= $this->element('genericElementsBS5/Forms/choice_slider', [
                    'field'    => 'threat_level_id',
                    'value'    => $threatLevelId,
                    'options'  => $threatStops,
                    'readonly' => true,
                ]) ?>
            </div>

        </div>

        <?php $moreUid = 'evtmore-' . ($event['id'] ?? '0'); ?>
        <div class="collapse" id="<?= h($moreUid) ?>">

        <div class="row g-2 align-items-start mb-3">

            <!-- CORRELATION -->
            <div class="col-12 col-md-6">
                <div class="text-muted small text-uppercase fw-bold mb-1">
                    <?= __('Correlation') ?>
                </div>
                <?= $this->element('genericElementsBS5/Badges/boolean', [
                    'boolean'    => !$disableCorrel,
                    'full'       => true,
                    'true'       => __('Enabled'),
                    'false'      => __('Disabled'),
                    'trueColor'  => 'success',
                    'falseColor' => 'danger',
                    'trueIcon'   => 'fa-link',
                    'falseIcon'  => 'fa-unlink',
                ]); ?>
            </div>

            <!-- STATUS: LOCKED + PROTECTED -->
            <div class="col-12 col-md-6">
                <div class="text-muted small text-uppercase fw-bold mb-1">
                    <?= __('Status') ?>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if (!empty($event['locked'])): ?>
                        <span class="badge text-bg-secondary d-inline-flex align-items-center px-2 py-1">
                            <i class="fas fa-lock me-1"></i>
                            <?= __('Locked') ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($event['protected']) && $event['protected'] === true): ?>
                        <span class="badge d-inline-flex align-items-center px-2 py-1"
                              style="background:var(--misp-tone-yellow-bg, #fff3cd);color:var(--misp-tone-yellow-fg, #856404);border:1px solid var(--misp-tone-yellow-fg, #856404);font-weight:500;"
                              data-bs-toggle="tooltip"
                              title="<?= __('Protected events can only be updated by signatories') ?>">
                            <i class="fas fa-shield-alt me-1"></i>
                            <?= __('Protected') ?>
                        </span>
                    <?php else: ?>
                        <span class="badge d-inline-flex align-items-center px-2 py-1"
                              style="background:var(--misp-tone-gray-bg, #e2e3e5);color:var(--misp-tone-gray-fg, #41464b);border:1px solid var(--misp-tone-gray-fg, #41464b);font-weight:500;"
                              data-bs-toggle="tooltip"
                              title="<?= __('Unprotected events can be updated by any user with write access to the event') ?>">
                            <i class="fas fa-shield-alt me-1"></i>
                            <?= __('Unprotected') ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- EXTENSIONS: what this event extends, what extends it -->
            <?= $this->element('Events/View/event_extensions', [
                'data' => $data,
            ]) ?>

        </div>

        </div><!-- /#<?= h($moreUid) ?> -->

        <div class="text-center border-top pt-2 mt-2">
            <button type="button"
                    class="btn btn-sm btn-link text-decoration-none text-muted ov-more-toggle collapsed"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= h($moreUid) ?>"
                    aria-expanded="false"
                    aria-controls="<?= h($moreUid) ?>">
                <span class="ov-more-open"><?= __('More details') ?></span>
                <span class="ov-more-close"><?= __('Fewer details') ?></span>
                <i class="fas fa-chevron-down ms-1 ov-more-chevron"></i>
            </button>
        </div>

    </div>
</div>

<?php if ($erHasReport): ?>
    <script>
    (function () {
        var raw       = <?= json_encode($erContent) ?>;
        var bodyId    = <?= json_encode($erBodyId) ?>;
        var cardId    = <?= json_encode($erCardId) ?>;
        var overlayId = <?= json_encode($erOverlayId) ?>;
        var maxH      = <?= json_encode($erMaxH) ?>;

        function checkOverflow() {
            var card    = document.getElementById(cardId);
            var overlay = document.getElementById(overlayId);
            if (!card || !overlay) { return; }
            if (card.scrollHeight > card.offsetHeight + 4) {
                overlay.style.display = 'block';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            var target = document.getElementById(bodyId);
            if (!target) { return; }
            function render() {
                if (window.markdownit) {
                    var md = window.markdownit({
                        html: false, linkify: true, typographer: true
                    });
                    target.innerHTML = md.render(raw);
                    checkOverflow();
                } else {
                    setTimeout(render, 100);
                }
            }
            render();
        });

    }());
    </script>
<?php endif; ?>
