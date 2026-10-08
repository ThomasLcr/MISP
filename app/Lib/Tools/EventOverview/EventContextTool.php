<?php
App::uses('ValueLabelPriority', 'Tools/ValueProfile');

/**
 * Sorts an event's labels into the rows the overview draws — markings,
 * attribution, behaviour, classification, mitigation — in the order the
 * reader's analyst profile asks for. Labels found only on the event's
 * indicators join their row marked as such, with how many carry them.
 *
 * Pure: the caller fetches, applies the ACL and passes everything in.
 */
class EventContextTool
{
    /** Who or what is behind it, when the profile names no attribution list. */
    const DEFAULT_ATTRIBUTION = array(
        'threat-actor', 'mitre-intrusion-set', 'mitre-enterprise-attack-intrusion-set',
        'mitre-mobile-attack-intrusion-set', 'mitre-pre-attack-intrusion-set', 'mitre-ics-groups',
        'groups', 'microsoft-activity-group', '360net-threat-actor', 'campaigns', 'mitre-campaign',
        'malpedia', 'ransomware', 'backdoor', 'banker', 'stealer', 'wiper', 'rat', 'botnet',
        'mitre-malware', 'mitre-enterprise-attack-malware', 'mitre-mobile-attack-malware',
        'mitre-ics-software', 'tool', 'mitre-tool', 'mitre-enterprise-attack-tool',
        'mitre-mobile-attack-tool', 'exploit-kit', 'android', 'stalkerware', 'cryptominers',
    );

    /**
     * @param array $eventTags EventTag rows, each carrying Tag
     * @param array $eventClusters GalaxyCluster rows on the event, each carrying Galaxy
     * @param array|null $rollup tag id => indicators carrying it; null when not computed
     * @param array $rollupTags tag id => Tag row, for the rolled-up ids
     * @param array $rollupClusters tag id => GalaxyCluster row (with Galaxy) for rolled-up galaxy tags
     * @param array|null $profile The reader's analyst profile
     * @param array $permitted taxonomies/galaxies the instance enables, as pivotLabels() gives it
     * @return array
     */
    public static function rows(
        array $eventTags,
        array $eventClusters,
        $rollup,
        array $rollupTags,
        array $rollupClusters,
        $profile,
        array $permitted = []
    ) {
        $plan = ValueLabelPriority::planFor($profile);
        $markingNamespaces = ValueLabelPriority::markings($profile);
        $attribution = array_flip(ValueLabelPriority::attribution($profile) ?: self::DEFAULT_ATTRIBUTION);
        $rollup = is_array($rollup) ? $rollup : [];

        $rows = [
            'markings' => [],
            'attribution' => [],
            'behaviour' => [],
            'classification' => [],
            'clusters' => [],
            'mitigation' => [],
        ];

        $seenTagIds = [];
        $clusterTagIds = [];
        foreach ($eventClusters as $cluster) {
            if (!empty($cluster['tag_id'])) {
                $clusterTagIds[(int)$cluster['tag_id']] = true;
            }
        }

        foreach ($eventTags as $eventTag) {
            $tag = $eventTag['Tag'] ?? null;
            if (empty($tag) || !empty($tag['hide_tag'])) {
                continue;
            }
            $tagId = (int)$tag['id'];
            $seenTagIds[$tagId] = true;
            if (!empty($tag['is_galaxy']) && isset($clusterTagIds[$tagId])) {
                continue;
            }
            $namespace = ValueLabelPriority::namespaceOf($tag['name']);
            $item = [
                'kind' => 'tag',
                'tag' => $eventTag,
                'key' => $namespace,
                'name' => $tag['name'],
                'level' => 'event',
                'count' => $rollup[$tagId] ?? 0,
            ];
            if ($namespace !== null && in_array($namespace, $markingNamespaces, true)) {
                $rows['markings'][] = $item;
            } else {
                $rows['classification'][] = $item;
            }
        }

        foreach ($eventClusters as $cluster) {
            $tagId = (int)($cluster['tag_id'] ?? 0);
            self::placeCluster($rows, $cluster, 'event', $rollup[$tagId] ?? 0, $attribution);
        }

        foreach ($rollup as $tagId => $count) {
            if (isset($seenTagIds[$tagId]) || empty($rollupTags[$tagId])) {
                continue;
            }
            $tag = $rollupTags[$tagId];
            if (!empty($tag['hide_tag'])) {
                continue;
            }
            if (!empty($tag['is_galaxy'])) {
                if (!empty($rollupClusters[$tagId])) {
                    self::placeCluster($rows, $rollupClusters[$tagId], 'indicators', $count, $attribution);
                }
                continue;
            }
            $namespace = ValueLabelPriority::namespaceOf($tag['name']);
            if ($namespace !== null && in_array($namespace, $markingNamespaces, true)) {
                continue;
            }
            $rows['classification'][] = [
                'kind' => 'tag',
                'tag' => ['Tag' => $tag, 'local' => 0],
                'key' => $namespace,
                'name' => $tag['name'],
                'level' => 'indicators',
                'count' => $count,
            ];
        }

        $rows['markings'] = self::orderMarkings($rows['markings'], $markingNamespaces);
        $rows['classification'] = self::byLevel(
            ValueLabelPriority::labels($rows['classification'], $plan, ValueLabelPriority::TAXONOMIES)
        );
        foreach (['attribution', 'clusters', 'mitigation'] as $row) {
            $rows[$row] = self::byLevel(
                ValueLabelPriority::labels($rows[$row], $plan, ValueLabelPriority::GALAXIES)
            );
        }
        $rows['behaviour'] = self::byLevel($rows['behaviour']);

        $groups = [];
        foreach ($rows['classification'] as $item) {
            if ($item['key'] !== null) {
                $groups[$item['key']] = ['key' => $item['key']];
            }
        }
        $presentMarkings = [];
        foreach ($rows['markings'] as $item) {
            $presentMarkings[$item['key']] = ['key' => $item['key']];
        }
        $rows['markings_absent'] = array_values(array_filter(
            ValueLabelPriority::absent(
                array_values($presentMarkings),
                $plan,
                ValueLabelPriority::TAXONOMIES,
                $permitted['taxonomies'] ?? null
            ),
            function ($missing) use ($markingNamespaces) {
                return in_array($missing['key'], $markingNamespaces, true);
            }
        ));
        foreach ($markingNamespaces as $namespace) {
            $groups[$namespace] = ['key' => $namespace];
        }
        $absent = ValueLabelPriority::absent(
            array_values($groups),
            $plan,
            ValueLabelPriority::TAXONOMIES,
            $permitted['taxonomies'] ?? null
        );
        foreach (array_reverse($absent) as $missing) {
            array_unshift($rows['classification'], [
                'kind' => 'absent',
                'key' => $missing['key'],
                'level' => 'event',
                'count' => 0,
            ]);
        }

        return $rows;
    }

    private static function placeCluster(array &$rows, array $cluster, $level, $count, array $attribution)
    {
        $type = mb_strtolower((string)($cluster['Galaxy']['type'] ?? $cluster['type'] ?? ''));
        $item = [
            'kind' => 'cluster',
            'cluster' => $cluster,
            'key' => $type,
            'name' => $cluster['value'] ?? '',
            'level' => $level,
            'count' => $count,
        ];
        if (isset($attribution[$type])) {
            $rows['attribution'][] = $item;
        } elseif (strpos($type, 'attack-pattern') !== false) {
            $item['technique'] = self::techniqueId($cluster);
            $rows['behaviour'][] = $item;
        } elseif (strpos($type, 'course-of-action') !== false) {
            $item['technique'] = self::techniqueId($cluster);
            $rows['mitigation'][] = $item;
        } else {
            $rows['clusters'][] = $item;
        }
    }

    /**
     * An ATT&CK cluster's id, from its meta or from the "Name - T1234" value.
     *
     * @param array $cluster
     * @return string|null
     */
    public static function techniqueId(array $cluster)
    {
        $external = $cluster['meta']['external_id'] ?? null;
        if (is_array($external)) {
            $external = reset($external);
        }
        if (is_string($external) && $external !== '') {
            return $external;
        }
        if (preg_match('/ - ([A-Z]{1,3}\d{4}(?:\.\d{3})?)$/', (string)($cluster['value'] ?? ''), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * A technique's name without the trailing id its cluster value carries.
     */
    public static function techniqueName(array $cluster)
    {
        return preg_replace('/ - [A-Z]{1,3}\d{4}(?:\.\d{3})?$/', '', (string)($cluster['value'] ?? ''));
    }

    private static function orderMarkings(array $items, array $namespaces)
    {
        $rank = array_flip($namespaces);
        usort($items, function ($a, $b) use ($rank) {
            $ra = $rank[$a['key']] ?? PHP_INT_MAX;
            $rb = $rank[$b['key']] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return self::severity($a) <=> self::severity($b);
        });
        return $items;
    }

    private static function severity(array $item)
    {
        $order = ValueLabelPriority::HANDLING[$item['key']] ?? null;
        if ($order === null) {
            return 0;
        }
        $leaf = mb_strtolower(trim(substr($item['name'], strlen($item['key']) + 1), '"'));
        return $order[$leaf] ?? count($order);
    }

    /**
     * Event-level labels ahead of indicator-only ones, each half keeping
     * the order it was given.
     */
    private static function byLevel(array $items)
    {
        $event = $indicators = [];
        foreach ($items as $item) {
            if ($item['level'] === 'event') {
                $event[] = $item;
            } else {
                $indicators[] = $item;
            }
        }
        return array_merge($event, $indicators);
    }
}
