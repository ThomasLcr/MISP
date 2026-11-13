<?php
/**
 * An organisation as the value page's tables name it: its tile, then its
 * name, linking to the organisation. Reporting's Reported by and the
 * Occurrences card draw it alike.
 *
 * @var array $org id, name
 * @var bool $small a 16px tile, for a meta line (default false)
 */
$org = isset($org) ? $org : array();
$name = isset($org['name']) ? (string)$org['name'] : '';
$tile = $this->EventIndex->orgTile(
    array('id' => isset($org['id']) ? $org['id'] : null, 'name' => $name),
    empty($small) ? 20 : 16
);
$class = 'vp-orglink' . (empty($small) ? '' : ' is-sm');
if (empty($org['id'])) {
    printf('<span class="%s">%s<span>%s</span></span>', $class, $tile, h($name));
    return;
}
printf(
    '<a class="%s" href="%s">%s<span>%s</span></a>',
    $class,
    h($baseurl . '/organisations/view/' . (int)$org['id']),
    $tile,
    h($name)
);
