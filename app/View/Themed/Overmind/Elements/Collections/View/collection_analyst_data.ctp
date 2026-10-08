<?php
App::uses('ClassRegistry', 'Utility');

$collectionUuid = $data['Collection']['uuid'] ?? '';
if ($collectionUuid === '') {
    return;
}

$analystCount = ClassRegistry::init('Note')->countForObjectRecursive($me, $collectionUuid);

echo $this->element('AnalystData/add_controls', [
    'objectType' => 'Collection',
    'objectUuid' => $collectionUuid,
    'viewCount'  => $analystCount,
]);
