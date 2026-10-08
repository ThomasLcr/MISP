<?php

/**
 * Whether MISP already knows this value is not worth acting on.
 *
 * Typically the heaviest single row on a benign value, and the one
 * whose meaning depends entirely on **which kind of list matched** —
 * which is why the category resolution is a profile section of its
 * own.
 *
 * Two readings, and conflating them is the mistake this signal exists
 * to avoid:
 *
 * - **`false_positive`** — the list means *this is not an indicator*.
 *   A resolver address, an RFC1918 range, a top-1000 domain. Heavy
 *   negative points; the list is a judgement MISP ships.
 * - **`known`** — the list means *this is shared infrastructure*. A CDN
 *   front, a hosting range. It is not a false positive and it does not
 *   argue the value is harmless; it argues that the value cannot be
 *   attributed to one tenant. The default weight is therefore **zero**
 *   — the row is on the page saying so, counted for neither side, and
 *   the contradiction with wide reporting is named by an escalation
 *   rather than netted off in arithmetic.
 *
 * **A category nothing sets, today.** Upstream lists carry no
 * `category` and `Warninglist::__updateList()` drops the field on
 * import, so the resolution comes from the profile's
 * `reference.warninglist_category` override map, then from
 * `WarninglistCategory`'s name map, and only then from the table's own
 * column. Where nothing resolves, an unresolved hit reads as
 * `false_positive` — which is what MISP's own warning banner has always
 * meant by a hit, and the honest default until upstream lists carry
 * the field.
 *
 * Absence fires as `no_hit`: *"no warninglist hit, 84 lists checked"*
 * — and the lists checked is half of it: a hit against nothing is only
 * meaningful beside how much was looked at. It is worth `+1`, as the
 * other absence rows are worth `−1`: it fires on nearly every
 * value, so a larger weight is an offset on the quality rather than
 * evidence that tells two values apart.
 */
class LifecycleWarninglist extends ValueSignalBase
{
    public $id = 'lifecycle.warninglist';
    public $group = 'Lifecycle';
    public $evidence_class = self::EVIDENCE_AGGREGATE;
    public $reads = array('warninglist');
    public $absence_key = 'no_hit';
    /*
     * One of the two ledger-borne lean sources, with false-positive
     * sightings. A hit reads the value — *this is not an indicator*,
     * or *this is shared infrastructure* — so a hit anchors.
     *
     * **`no_hit` does not, and that is the whole reason the axis is
     * per-row.** *Nothing matched, 8 lists checked* is the control
     * case: it says the value is on no list MISP ships, which is not
     * the same statement as *the value is a threat*. Anchored, it
     * would become one — on a benign lean its `+1` would invert to
     * `−1` and could, on its own, make an uncontested value read as
     * disputed. It is a quality row: what it measures is that the
     * record survived the check.
     */
    public $axis = self::AXIS_LEAN;
    public $tab = 'general';

    public function __construct()
    {
        $this->description = __(
            'Whether the value hits a warninglist, and what kind of'
            . ' list it is.'
        );
        $this->points_schema = array(
            'no_hit' => array(
                'type' => 'int',
                'default' => 1,
                'label' => __('Points when nothing matched'),
            ),
            'false_positive_hit' => array(
                'type' => 'int',
                'default' => -38,
                'label' => __('Points for a false-positive list'),
            ),
            'known_hit' => array(
                'type' => 'int',
                'default' => 0,
                'label' => __('Points for a known-infrastructure list'),
            ),
        );
    }

    public function evaluate(array $context, array $config)
    {
        $warninglist = isset($context['warninglist'])
            ? $context['warninglist']
            : array();
        $hits = isset($warninglist['hits'])
            ? $warninglist['hits']
            : array();
        $checked = (int)($warninglist['lists_checked'] ?? 0);

        if (empty($hits)) {
            if (!$this->absenceFires($config, $context, 'warninglist')) {
                return null;
            }
            return $this->row(
                $this->points($config, 'no_hit'),
                __('No warninglist hit'),
                sprintf(
                    $checked === 1
                        ? __('%d list checked')
                        : __('%d lists checked'),
                    $checked
                ),
                $context,
                null,
                self::AXIS_QUALITY
            );
        }

        $names = array();
        foreach ($hits as $hit) {
            if (!empty($hit['name'])) {
                $names[] = $hit['name'];
            }
        }
        $category = isset($warninglist['category'])
            ? $warninglist['category']
            : null;
        $known = ($category === 'known');
        $points = $known
            ? $this->points($config, 'known_hit')
            : $this->points($config, 'false_positive_hit');
        $lead = empty($names) ? __('a warninglist') : $names[0];
        $signal = $known
            ? sprintf(
                __('Known infrastructure: %s'),
                $lead
            )
            : sprintf(
                __('Hits a known-benign warninglist: %s'),
                $lead
            );
        $evidence = $known
            ? __('Shared infrastructure — not attributable to one'
                . ' tenant, and not a false positive')
            : sprintf(
                $checked === 1
                    ? __('%d list checked')
                    : __('%d lists checked'),
                $checked
            );
        if (count($names) > 1) {
            $evidence = implode(', ', array_slice($names, 0, 4))
                . '; ' . $evidence;
        }

        return $this->row($points, $signal, $evidence, $context);
    }
}
