<?php

$safeId      = h($id);
$safeContext = h($context);
$safeOrgId   = h($me['org_id']);

$urlGraph  = $baseurl . '/sightings/viewSightings/'   . $safeId . '/' . $safeContext;
$urlAll    = $baseurl . '/sightings/listSightings/'   . $safeId . '/' . $safeContext;
$urlOrg    = $baseurl . '/sightings/listSightings/'   . $safeId . '/' . $safeContext . '/' . $safeOrgId;
$urlAdd    = $baseurl . '/sightings/add/' . $safeId;
?>

<div class="container-fluid py-3">

    <!-- ── Header ── -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="d-flex align-items-center gap-2 fw-semibold mb-0">
            <span class="d-inline-flex align-items-center justify-content-center rounded-2"
                  style="width:32px;height:32px;background:#fbceff;">
                <span class="misp-icon misp-icon-sighting misp-simple" style="color:#890096;font-size:.85rem;"></span>
            </span>
            <?= __('Sightings') ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="<?= __('Close') ?>"></button>
    </div>

    <!-- ── Tab nav ── -->
    <ul class="nav nav-pills gap-1 mb-3" id="sightingAdvTabs" role="tablist">

        <li class="nav-item" role="presentation">
            <button class="nav-ajax nav-link active" role="tab"
                    data-tab="graph"
                    data-url="<?= h($urlGraph) ?>">
                <i class="fas fa-chart-area me-1"></i><?= __('Graph') ?>
            </button>
        </li>

        <li class="nav-item" role="presentation">
            <button class="nav-ajax nav-link" role="tab"
                    data-tab="all"
                    data-url="<?= h($urlAll) ?>">
                <i class="fas fa-list me-1"></i><?= __('All') ?>
            </button>
        </li>

        <li class="nav-item" role="presentation">
            <button class="nav-ajax nav-link" role="tab"
                    data-tab="org"
                    data-url="<?= h($urlOrg) ?>">
                <span class="misp-icon misp-icon-organisation misp-simple me-1"></span><?= __('My org') ?>
            </button>
        </li>

        <?php if ($safeContext === 'attribute'): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-ajax nav-link" role="tab" data-tab="add">
                <i class="fas fa-plus me-1"></i><?= __('Add sighting') ?>
            </button>
        </li>
        <?php endif; ?>

    </ul>

    <!-- ── Content area ── -->
    <div id="sightingAdvContent"
         style="max-height:80vh;overflow-y:auto;overflow-x:auto;">
        <div class="d-flex justify-content-center align-items-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?= __('Loading…') ?></span>
            </div>
        </div>
    </div>

    <div class="px-4 py-3">
        <div data-sighting-pane="remote" class="ov-modal-index overflow-auto"></div>

        <?php if ($canAdd): ?>
        <div data-sighting-pane="add" class="d-none">
            <?= $this->Form->create('Sighting', [
                'url' => $baseurl . '/sightings/add/' . h($id),
                'novalidate' => true,
            ]) ?>
            <div class="d-flex flex-column gap-4">
                <div>
                    <?= $this->element('genericElementsBS5/Forms/section_label', [
                        'accent' => 'sighting',
                        'label' => __('Type'),
                    ]) ?>
                    <?= $this->element('genericElementsBS5/Forms/choice_cards', [
                        'field' => 'Sighting.type',
                        'accent' => 'sighting',
                        'value' => 0,
                        'ariaLabel' => __('Sighting type'),
                        'options' => [
                            ['value' => 0, 'title' => __('Sighting'), 'sub' => __('Seen in the wild'), 'icon' => 'fas fa-thumbs-up', 'tone' => 'var(--bs-success)'],
                            ['value' => 1, 'title' => __('False positive'), 'sub' => __('Seen, but benign'), 'icon' => 'fas fa-thumbs-down', 'tone' => 'var(--bs-danger)'],
                            ['value' => 2, 'title' => __('Expiration'), 'sub' => __('No longer valid from this date'), 'icon' => 'fas fa-clock', 'tone' => 'var(--bs-warning)'],
                        ],
                    ]) ?>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <?= $this->element('genericElementsBS5/Forms/section_label', [
                            'accent' => 'sighting',
                            'label' => __('Source'),
                            'for' => 'SightingSource',
                        ]) ?>
                        <?= $this->Form->text('source', [
                            'class' => 'form-control',
                            'placeholder' => __('honeypot, IDS sensor id, SIEM…'),
                        ]) ?>
                    </div>
                    <div class="col-md-6">
                        <?= $this->element('genericElementsBS5/Forms/section_label', [
                            'accent' => 'sighting',
                            'label' => __('Date'),
                            'required' => true,
                        ]) ?>
                        <?= $this->element('genericElementsBS5/Forms/date_field', [
                            'field' => 'Sighting.datetime',
                            'mode' => 'datetime',
                            'accent' => 'sighting',
                            'value' => gmdate('Y-m-d H:i:s'),
                            'required' => true,
                        ]) ?>
                    </div>
                </div>
                <?= $this->element('genericElementsBS5/Forms/json_field', [
                    'field' => 'Sighting.filters',
                    'label' => __('Filters'),
                    'accent' => 'sighting',
                    'shape' => 'object',
                    'rows' => 4,
                    'minHeight' => '110px',
                    'gutter' => false,
                    'placeholder' => '{ "to_ids": 1, "tags": ["tlp:white"] }',
                    'hint' => __('Optional. Only the attributes matching these restSearch filters are sighted.'),
                ]) ?>
            </div>
            <?= $this->element('genericElementsBS5/Forms/modal_footer', [
                'accent' => 'sighting',
                'hint' => __('Recorded for your organisation.'),
                'cancel' => ['label' => __('Close')],
                'submit' => ['label' => __('Add sighting'), 'icon' => 'fas fa-plus'],
            ]) ?>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var root = document.getElementById(<?= json_encode($uid) ?>);
    var remote = root.querySelector('[data-sighting-pane="remote"]');
    var addPane = root.querySelector('[data-sighting-pane="add"]');
    var msg = {
        loading: <?= json_encode(__('Loading…')) ?>,
        loadFailed: <?= json_encode(__('Failed to load content.')) ?>,
        requestFailed: <?= json_encode(__('Request failed, please try again.')) ?>,
        deleted: <?= json_encode(__('Sighting deleted.')) ?>,
        confirm: <?= json_encode(__('Delete this sighting?')) ?>,
        yes: <?= json_encode(__('Delete')) ?>,
        no: <?= json_encode(__('Cancel')) ?>
    };
    var current = 'graph';

    function spinner() {
        remote.innerHTML = '<div class="d-flex justify-content-center py-5">'
            + '<div class="spinner-border text-secondary" role="status">'
            + '<span class="visually-hidden">' + msg.loading + '</span></div></div>';
    }

    function loadRemote(url) {
        contentEl.innerHTML =
            '<div class="d-flex justify-content-center align-items-center py-5">'
            + '<div class="spinner-border text-primary" role="status">'
            + '<span class="visually-hidden"><?= __('Loading…') ?></span>'
            + '</div></div>';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) throw new Error(r.status);
                return r.text();
            })
            .then(function (html) {
                remote.innerHTML = html;
                remote.querySelectorAll('script').forEach(function (old) {
                    var s = document.createElement('script');
                    if (old.src) {
                        s.src = old.src;
                    } else {
                        s.textContent = old.textContent;
                    }
                    old.replaceWith(s);
                });
            })
            .catch(function () {
                remote.innerHTML = '<div class="alert alert-danger m-0">'
                    + '<i class="fas fa-triangle-exclamation me-2"></i>' + msg.loadFailed + '</div>';
            });
    }

    function show(key) {
        var btn = root.querySelector('[data-sighting-tab="' + key + '"]');
        if (!btn) return;
        current = key;
        root.querySelectorAll('[data-sighting-tab]').forEach(function (el) {
            el.classList.toggle('active', el === btn);
        });
        var isAdd = key === 'add';
        remote.classList.toggle('d-none', isAdd);
        if (addPane) addPane.classList.toggle('d-none', !isAdd);
        if (!isAdd) loadRemote(btn.dataset.url);
    }

    function changed(detail) {
        notifySightingChange(detail);
        if (typeof reloadEventViewIndexTab === 'function') reloadEventViewIndexTab();
    }

    root.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-sighting-tab]');
        if (tab) {
            e.preventDefault();
            show(tab.dataset.sightingTab);
            return;
        }

        var del = e.target.closest('[data-sighting-delete]');
        if (del) {
            e.preventDefault();
            var cell = del.closest('td');
            cell.dataset.original = cell.innerHTML;
            cell.innerHTML = '<span class="d-inline-flex align-items-center gap-1 small text-nowrap">'
                + msg.confirm
                + ' <button type="button" class="btn btn-danger btn-sm py-0" data-sighting-confirm="'
                + del.dataset.sightingDelete + '" data-raw-id="' + escapeHtml(del.dataset.rawId)
                + '">' + msg.yes + '</button>'
                + ' <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-sighting-keep>'
                + msg.no + '</button></span>';
            return;
        }

        var keep = e.target.closest('[data-sighting-keep]');
        if (keep) {
            var keepCell = keep.closest('td');
            keepCell.innerHTML = keepCell.dataset.original;
            return;
        }

        var confirmBtn = e.target.closest('[data-sighting-confirm]');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            postSighting(baseurl + '/sightings/quickDelete/' + confirmBtn.dataset.sightingConfirm
                + '/' + encodeURIComponent(confirmBtn.dataset.rawId) + '/' + <?= json_encode($context) ?>, '')
                .then(function (data) {
                    if (!data.saved) {
                        showToast(data.errors || msg.requestFailed, 'danger');
                        confirmBtn.disabled = false;
                        return;
                    }
                    showToast(msg.deleted, 'success');
                    confirmBtn.closest('tr').remove();
                    changed({});
                })
                .catch(function () {
                    showToast(msg.requestFailed, 'danger');
                    confirmBtn.disabled = false;
                });
        }
    });

    var form = addPane ? addPane.querySelector('form') : null;
    if (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            e.preventDefault();
            var submit = form.querySelector('[type="submit"]');
            var fd = new FormData(form);
            var when = fd.get('data[Sighting][datetime]') || '';
            fd.delete('data[Sighting][datetime]');
            var ts = Date.parse(when.replace(' ', 'T') + 'Z');
            if (!isNaN(ts)) fd.set('data[Sighting][timestamp]', Math.floor(ts / 1000));
            if (!(fd.get('data[Sighting][filters]') || '').trim()) fd.delete('data[Sighting][filters]');
            if (submit) submit.disabled = true;
            postSighting(form.getAttribute('action'), new URLSearchParams(fd).toString())
                .then(function (data) {
                    if (!data.saved) {
                        showToast(data.errors || msg.requestFailed, 'danger');
                        return;
                    }
                    showToast(data.success, 'success');
                    changed({ type: fd.get('data[Sighting][type]') });
                    show('all');
                })
                .catch(function () {
                    showToast(msg.requestFailed, 'danger');
                })
                .finally(function () {
                    if (submit) submit.disabled = false;
                });
        });
    }

    show(current);
})();
</script>
