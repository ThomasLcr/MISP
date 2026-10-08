<?php
/**
 * Rail slot of the overview's galaxy matrix: filled by event-matrix.js from
 * events/viewEventMatrix, and left empty when the event uses no matrix galaxy.
 */
$eventId = (int)($data['Event']['id'] ?? 0);
$suffix = $extensionSuffix ?? '';
?>
<div data-mx-slot data-url="<?= h($baseurl . '/events/viewEventMatrix/' . $eventId . $suffix) ?>"></div>

<div class="modal fade mx-modal" id="mx-modal" tabindex="-1" aria-labelledby="mx-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <div class="misp-icon-tile rounded-2 d-flex align-items-center justify-content-center"
                     style="width:32px;height:32px;--tile:var(--bs-galaxy);--tile-bg:color-mix(in srgb, var(--bs-galaxy) 18%, transparent);">
                    <i class="fas fa-map" data-mx-modal-icon></i>
                </div>
                <h5 class="modal-title" id="mx-modal-title" data-mx-modal-title><?= __('Galaxy matrix') ?></h5>
                <div class="mx-modal-galaxies" role="group" aria-label="<?= h(__('Galaxy')) ?>" data-mx-modal-galaxies hidden></div>
                <div class="mx-modal-legend">
                    <span><span class="mx-sw"></span><?= __('On the event') ?></span>
                    <span><span class="mx-sw is-ind"></span><?= __('Only on indicators') ?></span>
                </div>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="<?= h(__('Close')) ?>"></button>
            </div>
            <div class="modal-body" data-mx-modal-body></div>
        </div>
    </div>
</div>
