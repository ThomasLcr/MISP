<?php

/**
 * How recently anybody reported this value.
 *
 * The quality reading of age, and deliberately not the relevance axis.
 * Relevance asks *does this still matter today* and answers with a TTL,
 * a runway and an expiry; this asks the narrower
 * question a ledger can answer — **is the record still being added
 * to** — and pays a few points for a yes.
 *
 * Keeping both is not double-counting, because they read different
 * clocks. Relevance runs off the last *independent corroboration* and
 * can expire a value nobody has re-reported; this runs off the last
 * occurrence of any kind, so a value one organisation keeps re-encoding
 * scores here and still ages out there. Where they agree, they agree in
 * different vocabulary on different axes; where they disagree, the
 * disagreement is the interesting thing and both are on screen.
 *
 * **Interpolated between the two ends**, so there is no cliff: a value
 * last reported the day after `recent_days` is worth marginally less
 * than one reported the day before it, not `recent` less. A cliff in a
 * ledger row is a place where two readers with the same evidence and
 * one day between their page loads get materially different numbers.
 */
class LifecycleRecency extends ValueSignalBase
{
    public $id = 'lifecycle.recency';
    public $group = 'Lifecycle';
    public $evidence_class = self::EVIDENCE_AGGREGATE;
    public $reads = array('occurrences');
    public $tab = 'occurrences';

    public function __construct()
    {
        $this->description = __(
            'How recently this value was last reported by anybody.'
        );
        $this->points_schema = array(
            'recent' => array(
                'type' => 'int',
                'default' => 8,
                'label' => __('Points for a value reported recently'),
            ),
            'old' => array(
                'type' => 'int',
                'default' => -4,
                'label' => __('Points for a value nobody has touched'),
            ),
        );
        $this->config_schema = array(
            'recent_days' => array(
                'type' => 'int',
                'default' => 30,
                'label' => __('Days within which a report counts as'
                    . ' recent'),
            ),
            'old_days' => array(
                'type' => 'int',
                'default' => 365,
                'label' => __('Days after which it counts as old'),
            ),
        );
    }

    public function evaluate(array $context, array $config)
    {
        $occurrences = isset($context['occurrences'])
            ? $context['occurrences']
            : array();
        $newest = (int)($occurrences['newest'] ?? 0);
        if ($newest === 0) {
            return null;
        }
        $now = (int)($context['now'] ?? time());
        $days = (int)floor(($now - $newest) / 86400);
        $recentDays = (int)$this->setting($config, 'recent_days');
        $oldDays = max(
            $recentDays + 1,
            (int)$this->setting($config, 'old_days')
        );
        $recent = $this->points($config, 'recent');
        $old = $this->points($config, 'old');

        if ($days <= $recentDays) {
            $points = $recent;
        } elseif ($days >= $oldDays) {
            $points = $old;
        } else {
            $travelled = ($days - $recentDays)
                / ($oldDays - $recentDays);
            $points = $recent + ($old - $recent) * $travelled;
        }

        return $this->row(
            $points,
            sprintf(
                __('Last reported %s'),
                $this->agoPhrase($days)
            ),
            sprintf(
                __('Recent under %1$d days, old after %2$d'),
                $recentDays,
                $oldDays
            ),
            $context,
            $this->stampAsOf($newest, $context)
        );
    }
}
