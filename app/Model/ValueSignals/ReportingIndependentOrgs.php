<?php

/**
 * How many organisations independently hold an occurrence of this value.
 *
 * Often the heaviest row on a malicious value (+28 from four
 * organisations) and the page's own argument for what corroboration
 * means: an organisation is one voice however many events it puts the
 * value in, which is the same independence rule the lean derivation
 * applies to `to_ids` stances.
 *
 * **Aggregate evidence, so never windowed.** A `COUNT DISTINCT`
 * over the orgs holding an occurrence is cheap at any cardinality, and
 * bounding it to 90 days would make a long-lived value look narrowly
 * reported for no reason but its age.
 *
 * One organisation still fires. *"1 organisation reported it"* is the
 * median value's own row, and it is worth `per_org` rather than
 * nothing — what makes the median record thin is the rest of the
 * ledger, not a signal refusing to speak.
 *
 * **Trust-weighted**, and the heaviest row on
 * the page is the reason: the count of organisations becomes a *sum of
 * their grades*, so four organisations graded `B/B/C/D` contribute
 * `7 × (1.10 + 1.10 + 1.00 + 0.75)` rather than `7 × 4`. Capped after
 * the weighting and rounded once, at the end. With no grade in force
 * every factor is `1.0` and the sum is the count, to the unit.
 */
class ReportingIndependentOrgs extends ValueSignalBase
{
    public $id = 'reporting.independent_orgs';
    public $group = 'Reporting';
    public $evidence_class = self::EVIDENCE_AGGREGATE;
    public $reads = array('orgs');
    public $tab = 'occurrences';

    public function __construct()
    {
        $this->description = __(
            'How many organisations independently report this value.'
        );
        $this->points_schema = array(
            'per_org' => array(
                'type' => 'int',
                'default' => 7,
                'label' => __('Points per reporting organisation'),
            ),
            'cap' => array(
                'type' => 'int',
                'default' => 28,
                'label' => __('Most this signal may contribute'),
            ),
        );
        /*
         * The unit stays `per_org` under trust weighting, which is the
         * honest reading rather than an oversight: the next
         * organisation to report a value is one nobody has graded yet,
         * so `unrated` — `1.00` by default — is what it is worth. An
         * analyst who has moved `unrated` off `1.00` has moved this
         * falsifier's arithmetic with it, and the alternative is a
         * falsifiability line that guesses at a grade for an
         * organisation that has not spoken.
         */
        $this->unit = array(
            'points' => 'per_org',
            'cap' => 'cap',
            'one' => __('One more organisation reporting it'),
            'many' => __('%d more organisations reporting it'),
        );
        $this->config_schema = array(
            'named' => array(
                'type' => 'int',
                'default' => 4,
                'label' => __('Organisations named in the evidence line'),
            ),
        );
    }

    public function evaluate(array $context, array $config)
    {
        $orgs = isset($context['orgs']) ? $context['orgs'] : array();
        if (empty($orgs)) {
            return null;
        }
        $weighted = ValueTrustTool::inForce($context, $config);
        $ids = array();
        foreach ($orgs as $org) {
            if (isset($org['id'])) {
                $ids[] = (int)$org['id'];
            }
        }
        $names = $weighted
            ? ValueTrustTool::annotate($context, $orgs)
            : array();
        if (!$weighted) {
            foreach ($orgs as $org) {
                if (!empty($org['name'])) {
                    $names[] = $org['name'];
                }
            }
        }
        $count = count($orgs);
        /*
         * The whole of the weighting: a headcount becomes a sum of
         * grades. `weighOrgs` returns the count itself when nothing is
         * graded, so this line is the same arithmetic in both states
         * rather than a branch that has to be kept in step.
         */
        $voices = $weighted
            ? ValueTrustTool::weighOrgs($context, $ids)
            : $count;
        $points = $this->capped(
            $this->points($config, 'per_org') * $voices,
            $this->points($config, 'cap')
        );
        $named = (int)$this->setting($config, 'named');
        $shown = array_slice($names, 0, max(1, $named));
        $evidence = implode(', ', $shown);
        if (count($names) > count($shown)) {
            $evidence .= sprintf(
                __(' and %d more'),
                count($names) - count($shown)
            );
        }
        if ($weighted) {
            $evidence = ValueTrustTool::appendClause(
                $context,
                $evidence,
                $ids
            );
        }
        return $this->row(
            $points,
            $count === 1
                ? __('1 organisation reported it')
                : sprintf(
                    __('%d independent organisations reported it'),
                    $count
                ),
            $evidence,
            $context,
            $this->stampAsOf(
                $context['occurrences']['newest'] ?? null,
                $context
            )
        );
    }
}
