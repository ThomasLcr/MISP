<?php
/**
 * An organisation as the value page's tables name it: its tile, then its
 * name, linking to the organisation. Reporting's Reported by and the
 * Occurrences card draw it alike.
 *
 * @var array $org id, name
 */
$org = isset($org) ? $org : array();
$name = isset($org['name']) ? (string)$org['name'] : '';
$tile = $this->EventIndex->orgTile(
    array('id' => isset($org['id']) ? $org['id'] : null, 'name' => $name),
    20
);
if (empty($org['id'])) {
    printf('<span class="vp-orglink">%s<span>%s</span></span>', $tile, h($name));
    return;
}
printf(
    '<a class="vp-orglink" href="%s">%s<span>%s</span></a>',
    h($baseurl . '/organisations/view/' . (int)$org['id']),
    $tile,
    h($name)
);
