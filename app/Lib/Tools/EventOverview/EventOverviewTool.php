<?php
App::uses('RedisTool', 'Tools');
App::uses('ValueLabelPriority', 'Tools/ValueProfile');
App::uses('ValueStatsTool', 'Tools/ValueProfile');

/**
 * The figures behind the Overmind event overview: what the indicators are,
 * which labels sit on them, and which objects reference each other.
 *
 * Every query is scoped to one event and grouped, so its cost follows the
 * event's size and never the instance's; the ACL is applied to the grouped
 * rows in PHP, because the caller has already established that the user may
 * see the event and only element distributions are left to judge.
 */
class EventOverviewTool
{
    /** Attributes past which the label roll-up waits for an explicit ask. */
    const ROLLUP_LIMIT = 50000;

    /** Referencing objects past which the graph card draws a summary. */
    const GRAPH_LIMIT = 150;

    const CACHE_TTL = 86400;

    const CACHE_PREFIX = 'misp:event_overview:';

    const GROUPS = array('Network', 'File', 'Host', 'Email', 'Detection rules', 'Other');

    /** @var Model */
    private $Attribute;

    /** @var Model */
    private $Object;

    public function __construct()
    {
        $this->Attribute = ClassRegistry::init('MispAttribute');
        $this->Object = ClassRegistry::init('MispObject');
    }

    /**
     * The inventory group a type belongs to.
     *
     * @param string $type
     * @return string One of GROUPS
     */
    public static function groupOf($type)
    {
        $type = (string)$type;
        if (preg_match('/^(snort|yara|sigma|suricata|zeek|bro|kusto-query|pattern-in-)/', $type)) {
            return 'Detection rules';
        }
        if (strpos($type, 'email') === 0 || in_array($type, ['whois-registrant-email', 'target-email'], true)) {
            return 'Email';
        }
        if (preg_match('/^(ip-|domain|hostname|url|uri|link|port|AS$|mac-|user-agent|http-|ja3|jarm|hassh|x509|ssh-|dns-|onion-|community-id|favicon-mmh3|dkim)/', $type)) {
            return 'Network';
        }
        if (preg_match('/^(md5|sha|ssdeep|imphash|telfhash|tlsh|authentihash|vhash|cdhash|impfuzzy|pehash|filename|malware-sample|attachment|size-in-bytes|mime-type|pdb|stix2-pattern)/', $type)) {
            return 'File';
        }
        if (preg_match('/^(regkey|mutex|windows-|named pipe|process-state|cpe|vulnerability|weakness)/', $type)) {
            return 'Host';
        }
        return 'Other';
    }

    /**
     * Who may see what, for this one event.
     *
     * @param array $user
     * @param array $event Event row carrying org_id
     * @return array full, sgids, key (the cache scope)
     */
    public function scope(array $user, array $event)
    {
        $full = !empty($user['Role']['perm_site_admin'])
            || (int)$event['org_id'] === (int)$user['org_id'];
        if ($full) {
            return ['full' => true, 'sgids' => [], 'key' => 'full'];
        }
        $sgids = array_map('intval', $this->Attribute->SharingGroup->authorizedIds($user));
        sort($sgids);
        return [
            'full' => false,
            'sgids' => array_flip($sgids),
            'key' => 'o' . (int)$user['org_id'] . '-' . md5(implode(',', $sgids)),
        ];
    }

    private function visible(array $scope, $distribution, $sharingGroupId)
    {
        if ($scope['full']) {
            return true;
        }
        $distribution = (int)$distribution;
        if (in_array($distribution, [1, 2, 3, 5], true)) {
            return true;
        }
        return $distribution === 4 && isset($scope['sgids'][(int)$sharingGroupId]);
    }

    private function cached($kind, array $event, array $scope, callable $compute, $allowCompute = true)
    {
        $key = self::CACHE_PREFIX . $kind . ':' . (int)$event['id'] . ':' . (int)$event['timestamp'] . ':' . $scope['key'];
        try {
            $redis = RedisTool::init();
            $hit = $redis->get($key);
            if ($hit !== false) {
                return RedisTool::deserialize($hit);
            }
        } catch (Exception $e) {
            $redis = null;
        }
        if (!$allowCompute) {
            return null;
        }
        $value = $compute();
        if ($redis) {
            try {
                $redis->setex($key, self::CACHE_TTL, RedisTool::serialize($value));
            } catch (Exception $e) {
                // An uncached answer is still the answer
            }
        }
        return $value;
    }

    /**
     * Counts by group and type, detection-ready against context-only, plus
     * how many indicators are shared more narrowly than the event and the
     * objects by template name.
     *
     * @param array $user
     * @param array $event Event row: id, org_id, timestamp, distribution, sharing_group_id
     * @param array|null $reference Event row "narrower" is judged against,
     *        when that is not $event itself (an extension view's viewed event)
     * @return array
     */
    public function inventory(array $user, array $event, $reference = null)
    {
        $scope = $this->scope($user, $event);
        $kind = 'inventory-v2';
        if ($reference !== null) {
            $kind .= sprintf(':n%d-%d', (int)$reference['distribution'], (int)($reference['sharing_group_id'] ?? 0));
        }
        return $this->cached($kind, $event, $scope, function () use ($scope, $event, $reference) {
            return $this->computeInventory($scope, $event, $reference ?? $event);
        });
    }

    /**
     * One inventory out of several events' own, keyed by event id, the viewed
     * event first. Detection rules keep the event they come from.
     *
     * @param array $parts event id => inventory()
     * @return array
     */
    public static function mergeInventories(array $parts)
    {
        $merged = [
            'total' => 0, 'ids' => 0, 'context' => 0, 'narrower' => 0, 'narrower_in_objects' => 0,
            'groups' => [], 'objects' => ['total' => 0, 'by_name' => []], 'detection_rules' => [],
        ];
        $groups = [];
        foreach ($parts as $eventId => $part) {
            foreach (['total', 'ids', 'context', 'narrower', 'narrower_in_objects'] as $key) {
                $merged[$key] += (int)($part[$key] ?? 0);
            }
            foreach ($part['groups'] ?? [] as $group) {
                $name = $group['group'];
                if (!isset($groups[$name])) {
                    $groups[$name] = ['group' => $name, 'total' => 0, 'ids' => 0, 'in_objects' => 0, 'types' => []];
                }
                $groups[$name]['total'] += $group['total'];
                $groups[$name]['ids'] += $group['ids'];
                $groups[$name]['in_objects'] += $group['in_objects'];
                foreach ($group['types'] as $type) {
                    $t = &$groups[$name]['types'][$type['type']];
                    $t = ['type' => $type['type'], 'total' => ($t['total'] ?? 0) + $type['total'], 'ids' => ($t['ids'] ?? 0) + $type['ids']];
                    unset($t);
                }
            }
            foreach ($part['objects']['by_name'] ?? [] as $name => $count) {
                $merged['objects']['by_name'][$name] = ($merged['objects']['by_name'][$name] ?? 0) + $count;
            }
            foreach ($part['detection_rules'] ?? [] as $rule) {
                if (count($merged['detection_rules']) < 5) {
                    $merged['detection_rules'][] = $rule + ['event_id' => (int)$eventId];
                }
            }
        }
        arsort($merged['objects']['by_name']);
        $merged['objects']['total'] = array_sum($merged['objects']['by_name']);
        foreach (self::GROUPS as $name) {
            if (!isset($groups[$name])) {
                continue;
            }
            $types = array_values($groups[$name]['types']);
            usort($types, function ($a, $b) {
                return [$b['ids'], $b['total'], $a['type']] <=> [$a['ids'], $a['total'], $b['type']];
            });
            $groups[$name]['types'] = $types;
            $merged['groups'][] = $groups[$name];
        }
        return $merged;
    }

    private function computeInventory(array $scope, array $event, array $reference)
    {
        $rows = $this->Attribute->query(
            'SELECT a.type, a.to_ids, CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END AS loose,'
            . ' a.distribution AS ad,'
            . ' CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END AS asg,'
            . ' COALESCE(o.distribution, 5) AS od,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END AS osg,'
            . ' COUNT(*) AS n'
            . ' FROM attributes a LEFT JOIN objects o ON o.id = a.object_id'
            . ' WHERE a.event_id = ? AND a.deleted = 0 AND (a.object_id = 0 OR o.deleted = 0)'
            . ' GROUP BY a.type, a.to_ids, CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END, a.distribution,'
            . ' CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END, COALESCE(o.distribution, 5),'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END',
            [(int)$event['id']],
            false
        );

        $eventDist = (int)$reference['distribution'];
        $eventSg = (int)($reference['sharing_group_id'] ?? 0);
        $groups = [];
        foreach (self::GROUPS as $name) {
            $groups[$name] = ['group' => $name, 'total' => 0, 'ids' => 0, 'in_objects' => 0, 'types' => []];
        }
        $total = $ids = $narrower = $narrowerInObjects = 0;
        foreach ($rows as $row) {
            $r = $this->flatRow($row);
            $loose = (int)$r['loose'] === 1;
            if (!$this->visible($scope, $r['ad'], $r['asg'])
                || (!$loose && !$this->visible($scope, $r['od'], $r['osg']))
            ) {
                continue;
            }
            $n = (int)$r['n'];
            $isIds = !empty($r['to_ids']);
            $group = self::groupOf($r['type']);
            $g = &$groups[$group];
            $g['total'] += $n;
            $g['ids'] += $isIds ? $n : 0;
            $g['in_objects'] += $loose ? 0 : $n;
            if (!isset($g['types'][$r['type']])) {
                $g['types'][$r['type']] = ['type' => $r['type'], 'total' => 0, 'ids' => 0];
            }
            $g['types'][$r['type']]['total'] += $n;
            $g['types'][$r['type']]['ids'] += $isIds ? $n : 0;
            unset($g);
            $total += $n;
            $ids += $isIds ? $n : 0;

            list($dist, $sg) = self::effectiveDistribution($r, $loose, $eventDist, $eventSg);
            if (self::isNarrower($dist, $sg, $eventDist, $eventSg)) {
                $narrower += $n;
                $narrowerInObjects += $loose ? 0 : $n;
            }
        }
        foreach ($groups as $name => &$g) {
            $types = array_values($g['types']);
            usort($types, function ($a, $b) {
                return [$b['ids'], $b['total'], $a['type']] <=> [$a['ids'], $a['total'], $b['type']];
            });
            $g['types'] = $types;
        }
        unset($g);
        $groups = array_values(array_filter($groups, function ($g) {
            return $g['total'] > 0;
        }));

        return [
            'total' => $total,
            'ids' => $ids,
            'context' => $total - $ids,
            'narrower' => $narrower,
            'narrower_in_objects' => $narrowerInObjects,
            'groups' => $groups,
            'objects' => $this->objectCounts($scope, (int)$event['id']),
            'detection_rules' => $this->detectionRules($scope, (int)$event['id']),
        ];
    }

    private function flatRow(array $row)
    {
        $flat = [];
        foreach ($row as $part) {
            if (is_array($part)) {
                $flat += $part;
            }
        }
        return $flat;
    }

    private static function effectiveDistribution(array $r, $loose, $eventDist, $eventSg)
    {
        if ((int)$r['ad'] !== 5) {
            return [(int)$r['ad'], (int)$r['asg']];
        }
        if (!$loose && (int)$r['od'] !== 5) {
            return [(int)$r['od'], (int)$r['osg']];
        }
        return [$eventDist, $eventSg];
    }

    public static function isNarrower($dist, $sg, $eventDist, $eventSg)
    {
        if ($eventDist === 0) {
            return false;
        }
        if ($dist === $eventDist) {
            return $dist === 4 && $sg !== $eventSg;
        }
        if ($dist === 0) {
            return true;
        }
        if ($eventDist === 4) {
            return false;
        }
        return $dist === 4 || $dist < $eventDist;
    }

    private function objectCounts(array $scope, $eventId)
    {
        $rows = $this->Object->query(
            'SELECT o.name, o.distribution AS od,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END AS osg, COUNT(*) AS n'
            . ' FROM objects o WHERE o.event_id = ? AND o.deleted = 0'
            . ' GROUP BY o.name, o.distribution, CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END',
            [$eventId],
            false
        );
        $byName = [];
        foreach ($rows as $row) {
            $r = $this->flatRow($row);
            if (!$this->visible($scope, $r['od'], $r['osg'])) {
                continue;
            }
            $byName[$r['name']] = ($byName[$r['name']] ?? 0) + (int)$r['n'];
        }
        arsort($byName);
        return ['total' => array_sum($byName), 'by_name' => $byName];
    }

    /**
     * The names of the event's detection rules — few, and worth reading.
     */
    private function detectionRules(array $scope, $eventId)
    {
        $rows = $this->Attribute->query(
            'SELECT a.type, a.value1, a.comment, a.distribution AS ad,'
            . ' CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END AS asg,'
            . ' COALESCE(o.distribution, 5) AS od,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END AS osg,'
            . ' CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END AS loose, o.name AS object_name'
            . ' FROM attributes a LEFT JOIN objects o ON o.id = a.object_id'
            . " WHERE a.event_id = ? AND a.deleted = 0 AND (a.object_id = 0 OR o.deleted = 0)"
            . " AND a.type IN ('snort', 'yara', 'sigma', 'suricata', 'zeek', 'bro', 'kusto-query')"
            . ' ORDER BY a.id LIMIT 20',
            [$eventId],
            false
        );
        $rules = [];
        foreach ($rows as $row) {
            $r = $this->flatRow($row);
            $loose = (int)$r['loose'] === 1;
            if (!$this->visible($scope, $r['ad'], $r['asg'])
                || (!$loose && !$this->visible($scope, $r['od'], $r['osg']))
            ) {
                continue;
            }
            $rules[] = [
                'type' => $r['type'],
                'name' => self::ruleName($r['type'], (string)$r['value1'], (string)$r['comment']),
                'object' => $loose ? null : $r['object_name'],
            ];
            if (count($rules) === 5) {
                break;
            }
        }
        return $rules;
    }

    /**
     * What a rule calls itself: snort's msg, yara's rule name, sigma's title.
     */
    public static function ruleName($type, $value, $comment)
    {
        $patterns = [
            '/\bmsg\s*:\s*"([^"]+)"/i',
            '/^\s*(?:private\s+|global\s+)*rule\s+([A-Za-z0-9_]+)/m',
            '/^\s*title\s*:\s*(.+)$/mi',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $m)) {
                return trim($m[1], " \t\"'");
            }
        }
        if (trim($comment) !== '') {
            return trim($comment);
        }
        return mb_substr(trim(preg_replace('/\s+/', ' ', $value)), 0, 80);
    }

    /**
     * Labels found on the event's indicators, as tag id => indicators
     * carrying it. Null when the event is past ROLLUP_LIMIT and nobody has
     * asked yet — a cached answer is still served.
     *
     * @param array $user
     * @param array $event Event row: id, org_id, timestamp, attribute_count
     * @param bool $force
     * @return array|null
     */
    public function rollup(array $user, array $event, $force = false)
    {
        $split = $this->rollupSplit($user, $event, $force);
        if ($split === null) {
            return null;
        }
        return array_map(function ($counts) {
            return $counts[0] + $counts[1];
        }, $split);
    }

    /**
     * rollup(), split by where the indicators sit.
     *
     * @param array $user
     * @param array $event
     * @param bool $force
     * @return array|null tag id => [loose attributes, attributes in objects]
     */
    public function rollupSplit(array $user, array $event, $force = false)
    {
        $scope = $this->scope($user, $event);
        $allowCompute = $force || (int)($event['attribute_count'] ?? 0) <= self::ROLLUP_LIMIT;
        return $this->cached('rollup-split', $event, $scope, function () use ($scope, $event) {
            return $this->computeRollup($scope, (int)$event['id']);
        }, $allowCompute);
    }

    private function computeRollup(array $scope, $eventId)
    {
        $rows = $this->Attribute->query(
            'SELECT atg.tag_id, a.distribution AS ad,'
            . ' CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END AS asg,'
            . ' CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END AS loose,'
            . ' COALESCE(o.distribution, 5) AS od,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END AS osg,'
            . ' COUNT(*) AS n'
            . ' FROM attribute_tags atg'
            . ' JOIN attributes a ON a.id = atg.attribute_id'
            . ' LEFT JOIN objects o ON o.id = a.object_id'
            . ' WHERE atg.event_id = ? AND a.deleted = 0 AND (a.object_id = 0 OR o.deleted = 0)'
            . ' GROUP BY atg.tag_id, a.distribution, CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END,'
            . ' CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END, COALESCE(o.distribution, 5),'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END',
            [$eventId],
            false
        );
        $counts = [];
        foreach ($rows as $row) {
            $r = $this->flatRow($row);
            $loose = (int)$r['loose'] === 1;
            if (!$this->visible($scope, $r['ad'], $r['asg'])
                || (!$loose && !$this->visible($scope, $r['od'], $r['osg']))
            ) {
                continue;
            }
            $tagId = (int)$r['tag_id'];
            $counts[$tagId] = $counts[$tagId] ?? [0, 0];
            $counts[$tagId][$loose ? 0 : 1] += (int)$r['n'];
        }
        return $counts;
    }

    /**
     * When the event's attributes and objects last changed: the earliest
     * such timestamp and a `Y-m-d` => count map, one count per element.
     *
     * @param array $user
     * @param array $event Event row: id, org_id, timestamp
     * @return array first (int|null), days
     */
    public function activity(array $user, array $event)
    {
        $scope = $this->scope($user, $event);
        return $this->cached('activity', $event, $scope, function () use ($scope, $event) {
            return $this->computeActivity($scope, (int)$event['id']);
        });
    }

    private function computeActivity(array $scope, $eventId)
    {
        // Hour buckets keep the rows bounded and let PHP's own timezone name the day.
        $attributes = $this->Attribute->query(
            'SELECT FLOOR(a.timestamp / 3600) AS h, MIN(a.timestamp) AS first,'
            . ' a.distribution AS ad,'
            . ' CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END AS asg,'
            . ' CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END AS loose,'
            . ' COALESCE(o.distribution, 5) AS od,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END AS osg,'
            . ' COUNT(*) AS n'
            . ' FROM attributes a LEFT JOIN objects o ON o.id = a.object_id'
            . ' WHERE a.event_id = ? AND a.deleted = 0 AND (a.object_id = 0 OR o.deleted = 0)'
            . ' GROUP BY FLOOR(a.timestamp / 3600), a.distribution,'
            . ' CASE WHEN a.distribution = 4 THEN a.sharing_group_id ELSE 0 END,'
            . ' CASE WHEN a.object_id = 0 THEN 1 ELSE 0 END, COALESCE(o.distribution, 5),'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END',
            [$eventId],
            false
        );
        $objects = $this->Object->query(
            'SELECT FLOOR(o.timestamp / 3600) AS h, MIN(o.timestamp) AS first,'
            . ' o.distribution AS od,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END AS osg,'
            . ' COUNT(*) AS n'
            . ' FROM objects o WHERE o.event_id = ? AND o.deleted = 0'
            . ' GROUP BY FLOOR(o.timestamp / 3600), o.distribution,'
            . ' CASE WHEN o.distribution = 4 THEN o.sharing_group_id ELSE 0 END',
            [$eventId],
            false
        );

        $first = null;
        $days = [];
        $add = function (array $r) use (&$first, &$days) {
            $day = date('Y-m-d', (int)$r['h'] * 3600);
            $days[$day] = ($days[$day] ?? 0) + (int)$r['n'];
            $first = $first === null ? (int)$r['first'] : min($first, (int)$r['first']);
        };
        foreach ($attributes as $row) {
            $r = $this->flatRow($row);
            $loose = (int)$r['loose'] === 1;
            if ($this->visible($scope, $r['ad'], $r['asg'])
                && ($loose || $this->visible($scope, $r['od'], $r['osg']))
            ) {
                $add($r);
            }
        }
        foreach ($objects as $row) {
            $r = $this->flatRow($row);
            if ($this->visible($scope, $r['od'], $r['osg'])) {
                $add($r);
            }
        }
        ksort($days);
        return ['first' => $first, 'days' => $days];
    }

    /**
     * The modification map: `activity()` bucketed from the first change to
     * today, at the value profile's rail grain.
     *
     * @param array $activity From `activity()`
     * @param string $today `Y-m-d`
     * @return array|null From `ValueStatsTool::timeHistogram()`
     */
    public static function activityHistogram(array $activity, $today)
    {
        if (empty($activity['days'])) {
            return null;
        }
        $days = array_keys($activity['days']);
        $span = ['from' => $days[0], 'to' => max(end($days), $today)];
        return ValueStatsTool::timeHistogram($span, $activity['days']);
    }

    /**
     * @param array $eventIds
     * @return bool whether any of the events has an object reference
     */
    public function hasReferences(array $eventIds)
    {
        return (bool)$this->Object->ObjectReference->find('first', [
            'recursive' => -1,
            'conditions' => [
                'ObjectReference.event_id' => array_map('intval', $eventIds),
                'ObjectReference.deleted' => 0,
            ],
            'fields' => ['ObjectReference.id'],
        ]);
    }

    /**
     * The event's referencing objects and the attributes they reference,
     * shaped as the Pivot Explorer's builder reads an event, or a summary
     * when there are too many to draw.
     *
     * @param array $user
     * @param array $event Event row with Orgc/Org alongside
     * @param array|null $eventIds an extension set to draw instead of the event alone
     * @return array graph (event-shaped payload or null), total, drawn
     */
    public function graph(array $user, array $event, $eventIds = null)
    {
        $eventId = empty($eventIds) ? (int)$event['Event']['id'] : array_map('intval', $eventIds);
        $refs = $this->Object->ObjectReference->find('all', [
            'recursive' => -1,
            'conditions' => [
                'ObjectReference.event_id' => $eventId,
                'ObjectReference.deleted' => 0,
            ],
            'fields' => [
                'ObjectReference.object_id', 'ObjectReference.referenced_id',
                'ObjectReference.referenced_type',
            ],
        ]);
        $ids = [];
        $attributeIds = [];
        foreach ($refs as $ref) {
            $ref = $ref['ObjectReference'];
            $ids[(int)$ref['object_id']] = true;
            if ((int)$ref['referenced_type'] === 1) {
                $ids[(int)$ref['referenced_id']] = true;
            } else {
                $attributeIds[(int)$ref['referenced_id']] = true;
            }
        }
        if (count($ids) > self::GRAPH_LIMIT) {
            return ['graph' => null, 'total' => count($ids), 'limit' => self::GRAPH_LIMIT];
        }

        // A referenced attribute brings in its object, or stands alone.
        $standaloneIds = [];
        if (!empty($attributeIds)) {
            $targets = $this->Attribute->find('all', [
                'recursive' => -1,
                'conditions' => [
                    'Attribute.id' => array_keys($attributeIds),
                    'Attribute.event_id' => $eventId,
                    'Attribute.deleted' => 0,
                ],
                'fields' => ['Attribute.id', 'Attribute.object_id'],
            ]);
            foreach ($targets as $target) {
                $objectId = (int)$target['Attribute']['object_id'];
                if ($objectId) {
                    $ids[$objectId] = true;
                } else {
                    $standaloneIds[] = (int)$target['Attribute']['id'];
                }
            }
        }
        $total = count($ids) + count($standaloneIds);
        if ($total === 0 || $total > self::GRAPH_LIMIT) {
            return ['graph' => null, 'total' => $total, 'limit' => self::GRAPH_LIMIT];
        }

        $objects = $this->Object->fetchObjects($user, [
            'conditions' => [
                'Object.event_id' => $eventId,
                'Object.deleted' => 0,
                'Object.id' => array_keys($ids),
            ],
            'contain' => [
                'ObjectReference' => [
                    'conditions' => ['ObjectReference.deleted' => 0],
                ],
            ],
        ]);
        $standalone = [];
        if (!empty($standaloneIds)) {
            foreach ($this->Attribute->fetchAttributes($user, [
                'conditions' => ['Attribute.id' => $standaloneIds],
                'flatten' => 1,
                'includeAllTags' => true,
            ]) as $row) {
                $standalone[] = $row['Attribute'] + ['AttributeTag' => $row['AttributeTag'] ?? []];
            }
        }

        $visible = [];
        foreach ($standalone as $attribute) {
            $visible[strtolower($attribute['uuid'])] = true;
        }
        foreach ($objects as $object) {
            $visible[strtolower($object['Object']['uuid'])] = true;
            foreach ($object['Attribute'] ?? [] as $attribute) {
                $visible[strtolower($attribute['uuid'])] = true;
            }
        }
        $shaped = [];
        foreach ($objects as $object) {
            $row = $object['Object'];
            $row['Attribute'] = [];
            foreach ($object['Attribute'] ?? [] as $attribute) {
                if (empty($attribute['deleted'])) {
                    $row['Attribute'][] = $this->graphAttribute($attribute);
                }
            }
            $row['ObjectReference'] = [];
            foreach ($object['ObjectReference'] ?? [] as $ref) {
                if (isset($visible[strtolower($ref['referenced_uuid'])])) {
                    $row['ObjectReference'][] = $ref;
                }
            }
            $shaped[] = $row;
        }

        $payload = $event;
        $payload['Event']['Attribute'] = array_map([$this, 'graphAttribute'], $standalone);
        $payload['Event']['Object'] = $shaped;
        $payload['Event']['Orgc'] = $event['Orgc'] ?? [];
        $payload['Event']['Org'] = $event['Org'] ?? [];
        return ['graph' => $payload, 'total' => $total, 'limit' => self::GRAPH_LIMIT];
    }

    /**
     * @param array $attribute Attribute row with AttributeTag alongside
     * @return array the attribute with its tags as the builder reads them
     */
    private function graphAttribute(array $attribute)
    {
        $attribute['Tag'] = [];
        foreach ($attribute['AttributeTag'] ?? [] as $at) {
            if (!empty($at['Tag'])) {
                $attribute['Tag'][] = $at['Tag'] + ['local' => $at['local'] ?? 0];
            }
        }
        unset($attribute['AttributeTag']);
        return $attribute;
    }
}
