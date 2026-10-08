<?php

App::uses('ValueStatementTool', 'Tools/ValueProfile');
App::uses('ValueTrustTool', 'Tools/ValueProfile');

/**
 * A reporter's own warning that the value may be harmless.
 *
 * The `false-positive` taxonomy lets a reporter say, on the occurrence
 * or on its event, how likely the value is to be a false positive —
 * `risk` `low` to `high` — or that it is one (`confirmed="true"`). A
 * `high` risk or a confirmation turns that reporter's voice benign, a
 * `medium` one half of it; a low risk, or one it cannot judge, is no
 * voice.
 *
 * **A voice, not a second one.** The lean's stance count reads the
 * same warnings, and an organisation that flags the value and warns
 * about it has one voice, split — not a threat voice and a benign one.
 * This row is drawn so the warning is on the page; it does not decide
 * the lean-disputed check, which the stance count already weighed.
 */
class ReportingFalsePositiveRisk extends ValueSignalBase
{
    public $id = 'reporting.false_positive_risk';
    public $group = 'Reporting';
    public $evidence_class = self::EVIDENCE_ROW;
    public $reads = array('statements');
    public $axis = self::AXIS_LEAN;
    public $voice = true;
    public $tab = 'occurrences';

    public function __construct()
    {
        $this->description = __(
            'A reporter tagging its own report with a false-positive'
            . ' risk, or confirming it is one.'
        );
        $this->points_schema = array(
            'per_voice' => array(
                'type' => 'int',
                'default' => -6,
                'label' => __('Points per reporter warning of a high'
                    . ' risk (a medium risk counts half)'),
            ),
            'cap' => array(
                'type' => 'int',
                'default' => -12,
                'label' => __('Most this signal may contribute'),
            ),
        );
    }

    public function evaluate(array $context, array $config)
    {
        $warnings = array_filter(
            ValueStatementTool::warningsByOrg($context),
            function ($warning) {
                return $warning['weight'] > 0.0;
            }
        );
        if (empty($warnings)) {
            return null;
        }
        $weighted = ValueTrustTool::inForce($context, $config);
        $voices = 0.0;
        $parts = array();
        $newest = 0;
        foreach ($warnings as $orgId => $warning) {
            $f = $weighted ? ValueTrustTool::factor($context, $orgId) : 1.0;
            $voices += $f * $warning['weight'];
            $parts[] = sprintf(__('%1$s warns of %2$s'),
                ValueStatementTool::orgName($context, $orgId),
                ValueStatementTool::warningLabel($warning['tag']));
            $newest = max($newest, (int)$warning['at']);
        }
        $points = $this->capped(
            $this->points($config, 'per_voice') * $voices,
            $this->points($config, 'cap')
        );
        $count = count($warnings);
        $signal = $count === 1
            ? __('Its reporter warns it may be a false positive')
            : sprintf(__('%d reporters warn it may be a false positive'),
                $count);
        $evidence = implode('; ', $parts);
        if ($weighted) {
            $evidence = ValueTrustTool::appendClause($context, $evidence,
                array_keys($warnings));
        }
        return $this->row(
            $points,
            $signal,
            $evidence,
            $context,
            $this->stampAsOf($newest, $context)
        );
    }
}
