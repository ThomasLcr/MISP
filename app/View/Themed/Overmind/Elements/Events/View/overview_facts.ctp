<?php
$eventId = (int)($data['Event']['id'] ?? 0);
?>
<div class="row g-3 mb-3 eo-row">
    <div class="col-12 col-xl-7">
        <div class="card shadow-sm eo-card w-100" id="eo-context-card"
             data-eo-fragment="<?= h($baseurl . '/events/viewEventContext/' . $eventId . ($extensionSuffix ?? '')) ?>">
            <div class="text-center text-muted py-5"><div class="misp-loader misp-loader-sm" role="status"></div></div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm eo-card w-100" id="eo-inventory-card"
             data-eo-fragment="<?= h($baseurl . '/events/viewEventInventory/' . $eventId . ($extensionSuffix ?? '')) ?>">
            <div class="text-center text-muted py-5"><div class="misp-loader misp-loader-sm" role="status"></div></div>
        </div>
    </div>
</div>
