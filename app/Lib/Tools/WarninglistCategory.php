<?php

/**
 * Which warninglists mean *shared infrastructure* rather than *false
 * positive*.
 *
 * `warninglists.category` exists — `varchar(20) NOT NULL DEFAULT
 * 'false_positive'`, validated against exactly
 * `['false_positive', 'known']` — and **nothing sets it.** Verified
 * against the sibling `misp-warninglists` checkout: `0` of `89`
 * `list.json` files carry a `category` field. And the gap is one layer
 * deeper than that, because MISP core would drop the field even if
 * upstream set it: `Warninglist::__updateList()` saves exactly
 * `['name', 'version', 'description', 'type', 'enabled']`, for shipped
 * lists and for `import()` alike. The column is only settable on custom
 * lists, through the add/edit controller path, which is why it is not
 * dead code (`07-reference.md` §3.1).
 *
 * Three things on the Value Profile page depend on the distinction, and
 * one of them cannot work without it at all: the escalation
 * `conflict:known-infrastructure-vs-reporting` requires
 * `warninglist_category: known` in its `when`, so **without a category
 * source it can never fire** — phase 3 shipped a rule that could not
 * reach its own precondition.
 *
 * ## Why the knowledge is code and not a store
 *
 * A category is a *fact about the list* — a Cloudflare edge range is
 * shared infrastructure regardless of who is looking — so the right
 * long-term home is `misp-warninglists`, where it serves every instance
 * and every non-MISP consumer of the repo. That path is currently dead
 * on arrival, so §3.3 stages the fix: **this file is V1**, a static map
 * following the `GalaxyColour` precedent — canonical knowledge shipped
 * as code, deterministic, no migration, no store. **V2** is two PRs
 * (upstream sets the field; core imports it), after which every entry
 * here is redundant and the file empties or goes.
 *
 * The retirement criterion is mechanical rather than editorial, which
 * is what `retirable()` is for: every roster entry's database row
 * carries the same category this map hardcodes.
 *
 * ## The test that decides membership
 *
 * **Can a competent report naming this value as malicious be
 * simultaneously true?**
 *
 * - **Yes → `known`.** The hit *contextualises* the report; acting on
 *   the value has collateral. An AWS range is `known` — C2 on EC2 is
 *   routine, and the address really is Amazon's.
 * - **No → `false_positive`.** The hit *refutes* the report, which is
 *   probably an extraction error or the impersonation target. An
 *   RFC1918 address is meaningless in shared intel by definition; a
 *   public DNS resolver stays `false_positive` because `8.8.8.8`
 *   reported as an IOC is nearly always an artifact of resolution
 *   logging — which is exactly how the benign demo value reads it.
 *
 * So the roster is shared and multi-tenant infrastructure plus research
 * scanners, and everything else — structural artifacts (`rfc*`,
 * `empty-hashes`, `tlds`), popularity lists (`tranco*`, `cisco_top*`,
 * `alexa`), vendor telemetry noise — stays `false_positive`, which the
 * fall-through already says. **The map carries only `known` entries.**
 *
 * ## Keyed by name, and that is fine
 *
 * `warninglists` has no `uuid` column and upstream lists carry none
 * either; MISP core itself matches shipped lists by name on every
 * update, so a rename upstream is already a new list as far as core is
 * concerned. Name-keying is no more fragile than the platform
 * underneath it. The residual risk — a renamed list silently losing its
 * entry — is handled by the standing requirement that the panel states
 * **which source supplied the category**, so a lost entry is visible
 * rather than silent.
 */
class WarninglistCategory
{
    /** The column's own default, and the fall-through. */
    const FALSE_POSITIVE = 'false_positive';

    /** What this map exists to say. */
    const KNOWN = 'known';

    /**
     * The whole vocabulary, in the order a reader meets it: `known` is
     * the distinction this file exists for, and `false_positive` is the
     * column's default that everything else falls through to.
     *
     * The pair is what `warninglists.category` validates against, so
     * anything offering a choice of category — the reference pane's
     * override map, both conflict rules' `when` — offers exactly this
     * and nothing else.
     */
    const CATEGORIES = array(self::KNOWN, self::FALSE_POSITIVE);

    /**
     * Where an answer came from, in the resolution order
     * `07-reference.md` §3.2 sets out. Carried per hit so the panel can
     * name it — the answer to the name-keying risk above, and to §5
     * item 12.
     */
    const SOURCE_PROFILE = 'profile';
    const SOURCE_SHIPPED = 'shipped';
    const SOURCE_LIST = 'list';
    const SOURCE_DEFAULT = 'default';

    /**
     * The roster, by each list's exact upstream `name`.
     *
     * Read off `misp-warninglists` rather than transcribed: the
     * directory names in §3.3 are not the names MISP stores, and
     * `amazon-aws`'s `name` is *"List of known Amazon AWS IP address
     * ranges"*. A key that does not match a real list's name is inert,
     * so the names are the load-bearing part of this file and the
     * directory each came from is in the comment beside it.
     */
    const KNOWN_LISTS = array(
        // amazon-aws
        'List of known Amazon AWS IP address ranges',
        // microsoft-azure, and its three regional siblings
        'List of known Microsoft Azure Datacenter IP Ranges',
        'List of known Microsoft Azure China Datacenter IP Ranges',
        'List of known Microsoft Azure Germany Datacenter IP Ranges',
        'List of known Microsoft Azure US Government Cloud Datacenter'
            . ' IP Ranges',
        // google-gcp
        'List of known GCP (Google Cloud Platform) IP address ranges',
        // ovh-cluster
        'List of known Ovh Cluster IP',
        // stackpath
        'List of known Stackpath CDN IP ranges',
        // cloudflare
        'List of known Cloudflare IP ranges',
        // akamai
        'List of known Akamai IP ranges',
        // fastly
        'List of known Fastly IP address ranges',
        // url-shortener
        'List of known URL Shorteners domains',
        // dynamic-dns
        'List of known dynamic DNS domains',
        // disposable-email
        'List of disposable email domains',
        // link-in-bio
        'List of known link in Bio domains',
        // parking-domain, parking-domain-ns
        'Parking domains',
        'Parking domains name server',
        // public-ipfs-gateways
        'List of known public IPFS gateways',
        // vpn-ipv4, vpn-ipv6
        'Specialized list of vpn-ipv4 addresses belonging to common VPN'
            . ' providers and datacenters',
        'Specialized list of IPv6 addresses belonging to common VPN'
            . ' providers and datacenters',
        // sinkholes — a sinkhole answering for a domain is real
        // infrastructure standing in for the threat, not a mistake
        'List of known sinkholes',
        // censys-scanning, tenable-cloud-*, check-host-net: research
        // scanners, whose traffic is genuinely theirs
        'Censys IP Ranges Used for Scanning',
        'List of known Tenable Cloud Sensors IPv4',
        'List of known Tenable Cloud Sensors IPv6',
        'List of known check-host.net IP address ranges',
    );

    /**
     * The shipped category for a list, or null when this map has
     * nothing to say about it.
     *
     * Null rather than `false_positive` on purpose: the resolution
     * order in §3.2 has two more steps below this one, and a map that
     * answered `false_positive` for every list it had never heard of
     * would shadow the database column — which for a *custom* list is
     * the one place the category is a deliberate statement.
     *
     * @param string|null $name The list's `name`, exactly
     * @return string|null
     */
    public static function categoryFor($name)
    {
        if (!is_string($name) && !is_numeric($name)) {
            return null;
        }
        return in_array((string)$name, self::KNOWN_LISTS, true)
            ? self::KNOWN
            : null;
    }

    /**
     * The four-step resolution, in one place so that the page, phase
     * 8's simulator and phase 10's worker cannot disagree about it.
     *
     * ```
     * 1. the profile's map, keyed by exact warninglist name
     * 2. the shipped map — this file
     * 3. warninglists.category from the database
     * 4. 'false_positive'                    # the column's own default
     * ```
     *
     * **The shipped map sits above the database deliberately.** For a
     * shipped list the column is not a statement, it is an unset
     * default — nothing imports it — while every entry in this map is
     * deliberate. Custom lists, where the column *is* settable and
     * deliberate, are simply absent from the map and fall through to
     * it. Under V2 the map empties and the order degenerates to
     * profile → database → default.
     *
     * @param string|null $name
     * @param string|null $column `warninglists.category` for this list
     * @param array $overrides The profile's `warninglist_category` map
     * @return array `category` and `source`
     */
    public static function resolve($name, $column,
        array $overrides = array()
    ) {
        $key = (string)$name;
        if (isset($overrides[$key])
            && self::valid($overrides[$key])
        ) {
            return array(
                'category' => (string)$overrides[$key],
                'source' => self::SOURCE_PROFILE,
            );
        }
        $shipped = self::categoryFor($key);
        if ($shipped !== null) {
            return array(
                'category' => $shipped,
                'source' => self::SOURCE_SHIPPED,
            );
        }
        if (self::valid($column)) {
            return array(
                'category' => (string)$column,
                'source' => self::SOURCE_LIST,
            );
        }
        return array(
            'category' => self::FALSE_POSITIVE,
            'source' => self::SOURCE_DEFAULT,
        );
    }

    /**
     * Whether a category is one MISP admits.
     *
     * `Warninglist.php`'s own validation enum, restated rather than
     * imported, because this class is pure and the enum is two strings
     * that have not changed since the column shipped. A profile
     * override naming something else is ignored and falls through —
     * §4's treatment for a map naming something that is not there,
     * applied to the value rather than the key.
     *
     * @param mixed $category
     * @return bool
     */
    public static function valid($category)
    {
        return $category === self::KNOWN
            || $category === self::FALSE_POSITIVE;
    }

    /**
     * What a category does and does not claim, in prose.
     *
     * **This is the signal on the page most routinely read backwards**,
     * and the band that carries it exists to say so
     * (`value_verdict_warninglist.ctp`). A hit is not a verdict about
     * the organisations that reported the value: `known` says an action
     * against this value will land on unrelated services too, and
     * `false_positive` says reports about it are usually collateral.
     * Neither says the reporting was wrong, and both sentences end by
     * saying that outright.
     *
     * It lives here rather than in the template because it is knowledge
     * about a category — the same reason `KNOWN_LISTS` is here — and
     * because the band is rendered from two layouts. A category with no
     * note gets none rather than a guessed one, which is the treatment
     * §4 gives every other unknown value.
     *
     * @param string|null $category
     * @return string|null
     */
    public static function note($category)
    {
        if ($category === self::KNOWN) {
            return __(
                'Category `known`: widely used infrastructure, not a'
                . ' false positive. Blocking it would also hit unrelated'
                . ' services; the reports are not wrong.'
            );
        }
        if ($category === self::FALSE_POSITIVE) {
            return __(
                'Category `false_positive`: this value often shows up'
                . ' in real incidents without being the indicator. The'
                . ' organisations that reported it are not wrong.'
            );
        }
        return null;
    }

    /**
     * Whether V1 can retire: every roster entry's database row already
     * carries the category this map hardcodes.
     *
     * The mechanical criterion §3.3 promised, in code so that the
     * question *"has V2 landed?"* is answered by an instance rather
     * than by reading two PRs. A list absent from this instance cannot
     * confirm anything, so it counts as outstanding — the criterion is
     * *every roster entry agrees*, and a missing row is not agreement.
     *
     * @param array $categories name => `warninglists.category`
     * @return array `retirable`, `confirmed`, `outstanding`
     */
    public static function retirable(array $categories)
    {
        $confirmed = array();
        $outstanding = array();
        foreach (self::KNOWN_LISTS as $name) {
            if (isset($categories[$name])
                && $categories[$name] === self::KNOWN
            ) {
                $confirmed[] = $name;
            } else {
                $outstanding[] = $name;
            }
        }
        return array(
            'retirable' => empty($outstanding),
            'confirmed' => $confirmed,
            'outstanding' => $outstanding,
        );
    }
}
