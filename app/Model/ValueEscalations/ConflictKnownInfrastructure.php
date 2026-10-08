<?php

App::uses('WarninglistCategory', 'Tools');

/**
 * MISP knows the value as shared infrastructure, and organisations
 * report it as a threat anyway.
 *
 * The contradiction that was on screen before anything computed it, and
 * the reason the warninglist signal weights a `known` hit at zero
 * rather than netting it off against the reporting. Both facts are
 * true: the value really is a CDN front or a hosting range, *and*
 * several organisations really did put it in an event with `to_ids`
 * set. Neither discounts the other.
 *
 * What arithmetic would do with that pair is pick the heavier side, and
 * both answers it can reach are wrong. Calling it a threat hides that
 * the value cannot be attributed to one tenant, so a reader blocks a
 * CDN edge. Calling it benign hides that people are reporting it, so a
 * reader stops looking at the one value several of their peers thought
 * worth flagging. The honest answer is the contradiction itself, which
 * is what this rule emits.
 *
 * **A single report is not a contradiction**, which is what
 * `min_independent_reports` is for. One organisation tagging a CDN
 * address is a mistake in one event, and a page that escalated on it
 * would escalate on every large hosting range MISP has ever seen. Three
 * organisations arriving at the same value independently is a pattern,
 * and a pattern against a `known` list is worth a reader's attention.
 */
class ConflictKnownInfrastructure extends ValueEscalationBase
{
    public $id = 'conflict:known-infrastructure-vs-reporting';
    public $reads = array('warninglist', 'orgs');
    public $tab = 'general';

    public function __construct()
    {
        $this->description = __(
            'A warninglist knows the value as shared infrastructure'
            . ' while several organisations report it as a threat.'
        );
        $this->when_schema = array(
            'warninglist_category' => array(
                'type' => 'string',
                'default' => WarninglistCategory::KNOWN,
                'options' => WarninglistCategory::CATEGORIES,
                'label' => __('The category of list that must match'),
            ),
            'min_independent_reports' => array(
                'type' => 'int',
                'default' => 3,
                'label' => __('Organisations reporting it, at least'),
            ),
        );
    }

    public function fires(array $context, array $config)
    {
        $category = $this->when($config, 'warninglist_category');
        $lists = $this->listsInCategory($context, $category);
        if (empty($lists)) {
            return null;
        }
        $orgs = $this->orgCount($context);
        $minimum = (int)$this->when($config, 'min_independent_reports');
        if ($orgs < $minimum) {
            return null;
        }
        return array(
            'prose' => sprintf(
                __('MISP knows this as shared infrastructure, and %d'
                    . ' organisations report it as a threat anyway.'
                    . ' Both are true; neither discounts the other.'),
                $orgs
            ),
            'evidence' => sprintf(
                __('%1$s · %2$d reporting organisations'),
                implode(', ', $lists),
                $orgs
            ),
        );
    }
}
