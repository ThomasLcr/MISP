<?php
$collection = $data['Collection'];

App::uses('CollectionType', 'Tools');

$titleHtml = h($collection['name'] ?? __('Collection'));
if (!empty($collection['type'])) {
    $typeLabel = CollectionType::label($collection['type']);
    $titleHtml = sprintf(
        '<span class="badge d-inline-flex align-items-center align-self-center py-1 me-2"'
        . ' style="background:rgba(var(--bs-primary-rgb),.12);color:var(--bs-primary);'
        . 'border:1px solid rgba(var(--bs-primary-rgb),.25);font-size:.55em;"'
        . ' data-bs-toggle="tooltip" title="%s" aria-label="%s"><i class="%s"></i></span>',
        h($typeLabel),
        h($typeLabel),
        h(CollectionType::icon($collection['type']))
    ) . $titleHtml;
}
$this->set('headerTitleHtml', $titleHtml);
$this->set('headerCountText', '');

$copyButton = function ($icon, $value, $title, $message) {
    return sprintf(
        '<button type="button" class="ov-copy-target focus-ring d-inline-flex align-items-center gap-1'
        . ' border-0 bg-transparent text-body-secondary px-2"'
        . ' title="%s" aria-label="%s" data-copy-value="%s" data-copy-msg="%s"'
        . ' onclick="copyValueToClipboard(this.dataset.copyValue, this.dataset.copyMsg)">'
        . '<i class="%s opacity-50"></i>%s<i class="fas fa-copy fa-xs"></i></button>',
        h($title),
        h($title),
        h($value),
        h($message),
        h($icon),
        h($value)
    );
};

$descParts = [
    '<span class="d-inline-flex align-items-stretch border rounded-pill bg-body-tertiary font-monospace overflow-hidden lh-base">'
    . $copyButton('fas fa-hashtag', $collection['id'], __('Copy ID'), __('ID copied to clipboard'))
    . '<span class="vr my-1"></span>'
    . $copyButton('fas fa-fingerprint', $collection['uuid'], __('Copy UUID'), __('UUID copied to clipboard'))
    . '</span>',
];
if (!empty($collection['created'])) {
    $descParts[] = '<span title="' . h(__('Created')) . '">'
        . '<i class="fas fa-calendar-day me-1 opacity-50"></i>'
        . $this->Time->time($collection['created'])
        . '</span>';
}
if (!empty($collection['modified'])) {
    $descParts[] = '<span title="' . h(__('Last modified')) . '">'
        . '<i class="fas fa-edit me-1 opacity-50"></i>'
        . $this->Time->time($collection['modified'])
        . '</span>';
}
$this->set(
    'headerDescription',
    '<span class="d-inline-flex align-items-center gap-3 flex-wrap">' . implode('', $descParts) . '</span>'
);

echo $this->element('genericElementsBS5/Layout/view_layout', [
    'data' => $data,
    'tabs' => [
        [
            'id' => 'general',
            'title' => __('General'),
            'icon' => 'fas fa-info-circle',
            'left' => [
                'Collections/View/collection_general',
                'Collections/View/collection_ownership',
                'Collections/View/collection_elements_card',
            ],
            'right' => [
                'Collections/View/collection_actions',
            ]
        ],
        [
            'id' => 'elements',
            'title' => __('Elements'),
            'icon' => 'fas fa-file',
            'left' => [
                'Collections/View/collection_elements',
            ],
        ]
    ]
]);
