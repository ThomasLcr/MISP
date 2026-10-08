<?php

/**
 * What role a galaxy's clusters play, so a panel can ask for the ones
 * that name a threat without hardcoding a list of galaxy names.
 *
 * MISP records nothing of the kind. `galaxies` carries `type`, `name`,
 * `namespace`, `icon` and `kill_chain_order`, and `namespace` groups by
 * *publisher* — misp, mitre, tidal, disarm, nist-nice — not by what the
 * clusters underneath are for. So the 130 galaxies misp-galaxy ships
 * are, to any consumer, one undifferentiated set in which Threat Actor
 * sits beside UKHSA Culture Collections, Firearms and Cancer.
 *
 * **The answer lives on the galaxy.** `galaxies.category` and
 * `galaxies.kind` were added by migration 164 and are ingested straight
 * from the definition files, because `__load_galaxies` saves whatever a
 * definition carries. This class reads that column and holds the
 * vocabulary the column is written against.
 *
 * **It used to carry an interim table of 94 galaxy types, and that
 * table is gone.** It was the answer before the field existed and the
 * fallback while instances took the release carrying it; it was deleted
 * once the column answered for every type it did. The condition was a
 * comparison rather than a count — *no type the table answers for is
 * one the column leaves unanswered* — because the count version, *no
 * galaxy carrying clusters is left without a category*, can never be
 * met: measured on the development instance, 14 of the 21 unclassified
 * types in use have no galaxy row at all, being legacy and STIX tag
 * strings whose galaxy no longer exists, so there is nothing to carry
 * a field for them. `prd/personas/07-category-live-probe.php`'s
 * `coverage` is what reports the comparison.
 *
 * **So an unclassified galaxy now reads as unclassified everywhere**,
 * including on an instance whose misp-galaxy copy predates the field
 * and inside a harness with no CakePHP, where the read cannot happen at
 * all. Both used to fall back to the table and now answer nothing. A
 * galaxy this class does not answer for is *unrecognised*, not judged
 * harmless, and every caller skips it — which is the same behaviour an
 * unlisted galaxy always had, reached by a different route.
 *
 * That is also the answer for a locally created galaxy, whose `type` is
 * a bare UUID no shipped classification can predict, until an
 * administrator classifies it on `galaxies/add` or `galaxies/edit`.
 * Those offer the vocabulary below and reach locally created galaxies
 * only: `edit()` refuses a default galaxy and `add()` forces
 * `default = false`, so a re-ingest cannot overwrite the answer.
 *
 * **Proposed upstream 2026-09-18**, with the deleted table as its
 * starting content: misp-galaxy `prd/2026-09-18-galaxy-category.md`,
 * which asks for `category` and `kind` on the galaxy definition, and
 * classifies 99 of the 135 shipped galaxies.
 *
 * **Why a named threat is a galaxy cluster and nothing else.** Measured
 * on the development instance, 2026-09-03:
 *
 *   freetext tags        10,840 exist, 173 used on events. Ranked by
 *                        event count the top two are the word
 *                        `malware` (165 events) and ` C2` (72), and
 *                        one malware family carries seven spellings
 *                        (`LummaC2`, `Lumma`, `LummaStealer`,
 *                        `lummaC`, ...). A card ranking these leads
 *                        with a category noun and double-counts
 *                        everything it does recognise.
 *   taxonomies           the three that sound like they name threats
 *                        classify instead: `malware_classification`
 *                        by category, `ms-caro-malware` by type and
 *                        platform, `adversary` by infrastructure
 *                        status. The only namespaced tags here that
 *                        do name one — `Threat:Sofacy/APT28`,
 *                        `Banker: TrickBot` — are absent from
 *                        `taxonomies`: freetext with a colon in it.
 *   galaxy clusters      curated, deduplicated, each with a page to
 *                        link to and an ACL to check.
 *
 * Attack patterns are excluded from `named-threat` on purpose: *where
 * in the intrusion* is a different question from *who*, it is answered
 * by its own group on the same card, and it is the single largest
 * galaxy on most values (21 of `8.8.8.8`'s 26 event clusters), so
 * folding it in would bury the names it sits beside.
 *
 * **It takes no `$user`, and it stopped being pure when the column
 * landed**: prd/value-profile-live/00-contract.md §14.5 allows both
 * shapes and this is now the second, a tool that issues its own read
 * and holds the result. No `$user` is needed and none would help — a
 * galaxy's category is a property of the definition, the same for
 * every viewer, and a caller only ever asks about a type it is already
 * holding from a label it is already allowed to see. What is read here
 * could not be used to widen what a reader sees.
 * prd/value-profile-live/24b-relationships.md §10.
 */
class GalaxyCategory
{
    /**
     * Clusters that name something conducting or constituting an
     * intrusion. The dividing line is curation, not menace: a
     * surveillance vendor is in because that galaxy is a curated list
     * of firms selling intrusion capability, while `intelligence-agency`
     * is out because it is a reference list of every agency there is.
     */
    const NAMED_THREAT = 'named-threat';

    /** Adversary behaviour — the *where in the intrusion* question. */
    const TECHNIQUE = 'technique';

    /** Who was hit: sector, place, target description. */
    const VICTIM = 'victim';

    /** Mitigations, countermeasures and controls. */
    const DEFENSIVE = 'defensive';

    /** Detection content: rules, analytics, data sources. */
    const DETECTION = 'detection';

    /** What is exposed: assets, platforms, services. */
    const TARGETING = 'targeting';

    /**
     * The near-misses, named so they are not mistaken for oversights.
     * `producer` and `intelligence-agency` are the two that read like
     * threats and are not: the first names who published the
     * intelligence, the second is a directory.
     */
    const CONTEXT = 'context';

    /**
     * The one category the deleted table never needed and the field
     * does. A table answers *is this a threat* by omission, so Firearms
     * and Cancer could simply be left out of it; a field on the galaxy
     * cannot work that way, because absent there has to mean
     * *undecided*. So the non-security galaxies say what they are.
     *
     * Nothing here asks for it. It is declared so the vocabulary in
     * code is the vocabulary upstream validates against, and so a
     * reader meeting `reference` in the column can find it.
     */
    const REFERENCE = 'reference';

    const ACTOR = 'actor';
    const CAMPAIGN = 'campaign';
    const MALWARE = 'malware';
    const TOOL = 'tool';

    /**
     * The `TECHNIQUE` kind whose clusters carry a `kill_chain` element,
     * which is what a tactic roll-up reads. Named because two callers
     * ask for it now — `isAttackPattern` and the tactic chain.
     */
    const ATTACK_PATTERN = 'attack-pattern';

    /**
     * The vocabulary itself: category => definition and the kinds in
     * use under it.
     *
     * This is what `galaxies/add` and `galaxies/edit` offer, and it is
     * the same eight values as misp-galaxy's proposed
     * `vocabularies/common/galaxy-category.json` — the definitions are
     * that file's, shortened to a line a form can carry. A galaxy
     * classified here has to mean the same thing as one classified
     * upstream, so the two lists cannot be allowed to drift.
     *
     * The kinds are *the kinds in use*, not a closed set: upstream's
     * schema does not constrain `kind` to its category, and `reference`
     * has none at all because the non-security galaxies are one
     * undifferentiated group. The form narrows to this map because
     * offering `actor` under `detection` would be offering nonsense,
     * not because a stored value outside it is invalid.
     *
     * @var array category => array('description' => string,
     *     'kinds' => array)
     */
    private static $vocabulary = array(
        self::NAMED_THREAT => array(
            'description' => 'The clusters name something conducting or constituting an intrusion. The dividing line is curation rather than menace: a curated list of firms selling intrusion capability belongs here, a directory of every agency that exists does not.',
            'kinds' => array(self::ACTOR, self::CAMPAIGN, self::MALWARE,
                self::TOOL),
        ),
        self::TECHNIQUE => array(
            'description' => 'The clusters describe adversary behaviour - where in an intrusion, and how. Attack patterns, techniques and tactics.',
            'kinds' => array(self::ATTACK_PATTERN, 'technique', 'tactic'),
        ),
        self::VICTIM => array(
            'description' => 'The clusters describe who was hit: a sector, a place, a target description.',
            'kinds' => array('sector', 'location', 'target'),
        ),
        self::DEFENSIVE => array(
            'description' => 'The clusters describe what to do about it: courses of action, mitigations, countermeasures, controls.',
            'kinds' => array('course-of-action', 'control'),
        ),
        self::DETECTION => array(
            'description' => 'The clusters describe how something would be caught: detection rules, analytics, detection strategies, data sources.',
            'kinds' => array('rule', 'strategy', 'data-source'),
        ),
        self::TARGETING => array(
            'description' => 'The clusters describe what is exposed: assets, platforms, services.',
            'kinds' => array('asset', 'platform', 'service'),
        ),
        self::CONTEXT => array(
            'description' => 'The clusters name entities that appear alongside threat intelligence without being a threat themselves - who published a report, a vendor, a branded vulnerability, a reference list of sources.',
            'kinds' => array('producer', 'organisation', 'vulnerability',
                'reference'),
        ),
        self::REFERENCE => array(
            'description' => 'The clusters are a reference list from another domain, carried in MISP for sharing rather than to describe an intrusion - an industry classification, a species list, an equipment catalogue.',
            'kinds' => array(),
        ),
    );

    /**
     * The categories ingested from the galaxy definitions.
     *
     * **Null until something asks**, and empty whenever the answer
     * cannot be had: outside CakePHP, where this class is a plain
     * `require` in a harness, and before migration 164, where the
     * columns do not exist. Both used to fall back to the interim
     * table; with it deleted they answer nothing, so every galaxy reads
     * as unclassified rather than as some other category.
     *
     * @var array|null type => array(category, kind)
     */
    private static $ingested = null;

    /**
     * @return array type => array(category, kind)
     */
    private static function ingested()
    {
        if (self::$ingested !== null) {
            return self::$ingested;
        }
        self::$ingested = array();
        if (!class_exists('ClassRegistry')) {
            return self::$ingested;
        }
        $model = ClassRegistry::init('Galaxy');
        $schema = $model->schema();
        /*
         * Asked of the schema rather than attempted and caught: an
         * instance that has not run the update is the normal state
         * during an upgrade, not an error worth a stack trace in the
         * log every time a value page is drawn.
         */
        if (!isset($schema['category']) || !isset($schema['kind'])) {
            return self::$ingested;
        }
        $rows = $model->find('all', array(
            'recursive' => -1,
            'fields' => array('Galaxy.type', 'Galaxy.category',
                'Galaxy.kind'),
            'conditions' => array('Galaxy.category !=' => ''),
        ));
        foreach ($rows as $row) {
            $row = $row['Galaxy'];
            if (empty($row['type']) || empty($row['category'])) {
                continue;
            }
            self::$ingested[$row['type']] = array(
                $row['category'],
                empty($row['kind']) ? null : $row['kind'],
            );
        }
        return self::$ingested;
    }

    /**
     * Drop what was read, so the next call reads again.
     *
     * For a shell that ingests galaxies and then asks about them in
     * the same process, and for a test that changes a row and wants the
     * next call to see it.
     *
     * @return void
     */
    public static function forget()
    {
        self::$ingested = null;
    }

    /**
     * @param string $galaxyType `galaxies.type`
     * @return array|null category and kind, or null if unrecognised
     */
    public static function of($galaxyType)
    {
        $galaxyType = (string)$galaxyType;
        $known = self::ingested();
        if (!isset($known[$galaxyType])) {
            return null;
        }
        return array(
            'category' => $known[$galaxyType][0],
            'kind' => $known[$galaxyType][1],
        );
    }

    /**
     * @param string $galaxyType
     * @return bool False for an unrecognised galaxy, which is the
     *     answer for every locally created one until the category
     *     lives on the galaxy itself.
     */
    public static function isNamedThreat($galaxyType)
    {
        $found = self::of($galaxyType);
        return $found !== null
            && $found['category'] === self::NAMED_THREAT;
    }

    /**
     * @param string $galaxyType
     * @return string|null `actor`, `campaign`, `malware`, `tool` for a
     *     named threat; the category's own kind otherwise
     */
    public static function kindOf($galaxyType)
    {
        $found = self::of($galaxyType);
        return $found === null ? null : $found['kind'];
    }

    /**
     * Whether this galaxy's clusters are ATT&CK-shaped techniques —
     * the ones that carry a `kill_chain` element naming their tactic,
     * and so the ones a tactic roll-up can place.
     *
     * The other two `TECHNIQUE` kinds are excluded and each for its own
     * reason. A `tactic` galaxy's clusters *are* tactics, so collapsing
     * them would have a tactic counting itself; a `technique` galaxy is
     * another framework's technique list, whose tactic vocabulary is
     * its own and does not belong on one strip with ATT&CK's.
     *
     * @param string $galaxyType
     * @return bool
     */
    public static function isAttackPattern($galaxyType)
    {
        $found = self::of($galaxyType);
        return $found !== null
            && $found['category'] === self::TECHNIQUE
            && $found['kind'] === self::ATTACK_PATTERN;
    }

    /**
     * Every galaxy type of one kind, for a caller that has to ask in
     * SQL rather than over rows already read.
     *
     * @param string $kind One of the `kinds` in the vocabulary above
     * @return array Galaxy types
     */
    public static function typesOfKind($kind)
    {
        $types = array();
        foreach (self::ingested() as $type => $pair) {
            if ($pair[1] === $kind) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * Every galaxy type in one category, for a caller that has to ask
     * in SQL rather than over rows already read.
     *
     * @param string $category One of the constants above
     * @return array Galaxy types
     */
    public static function typesIn($category)
    {
        $types = array();
        foreach (self::ingested() as $type => $pair) {
            if ($pair[0] === $category) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * The eight categories, in the order the vocabulary states them.
     *
     * @return array
     */
    public static function categories()
    {
        return array_keys(self::$vocabulary);
    }

    /**
     * The kinds in use under one category.
     *
     * @param string $category
     * @return array Empty both for `reference`, which has none, and for
     *     a category outside the vocabulary
     */
    public static function kindsIn($category)
    {
        if (!isset(self::$vocabulary[$category])) {
            return array();
        }
        return self::$vocabulary[$category]['kinds'];
    }

    /**
     * What a category means, in one line, for a reader choosing one.
     *
     * @param string $category
     * @return string|null
     */
    public static function describe($category)
    {
        if (!isset(self::$vocabulary[$category])) {
            return null;
        }
        return self::$vocabulary[$category]['description'];
    }
}
