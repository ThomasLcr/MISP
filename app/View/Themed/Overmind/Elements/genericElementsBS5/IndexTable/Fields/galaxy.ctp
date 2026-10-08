<?php
$data = Hash::extract($row, $field['data_path']);

/*
 * Optional inline "+" button to attach a galaxy cluster to this object.
 * Enabled by the caller via $field['add_galaxy'] (already ACL-gated upstream).
 * $field['add_galaxy_url'] holds a URL template with a %id% placeholder,
 * resolved from $field['add_galaxy_id_path'] (falls back to $row['id']).
 * $field['add_galaxy_relationship_url'] adds its sibling
 */
// A callable lets the caller decide per row — an extended event view
// grants the button on the rows of the events you can actually tag.
$allowAddGalaxy = $field['add_galaxy'] ?? false;
if (is_callable($allowAddGalaxy)) {
    $allowAddGalaxy = $allowAddGalaxy($row);
}
$allowAddGalaxy = !empty($allowAddGalaxy);
$addUrl         = null;
$addRelationshipUrl = null;
if ($allowAddGalaxy) {
    $addId = Hash::get($row, $field['add_galaxy_id_path'] ?? 'id');
    if (empty($addId) && !empty($row['id'])) {
        $addId = $row['id'];
    }
    $isDeleted = !empty($row['deleted']) || !empty($row['Attribute']['deleted']);
    if (!empty($addId) && !$isDeleted) {
        if (!empty($field['add_galaxy_url'])) {
            $addUrl = str_replace(
                '%id%', rawurlencode($addId), $field['add_galaxy_url']
            );
        }
        if (!empty($field['add_galaxy_relationship_url'])) {
            $addRelationshipUrl = str_replace(
                '%id%', rawurlencode($addId),
                $field['add_galaxy_relationship_url']
            );
        }
    }
}

if (empty($data)) {
    if (!empty($row['Galaxy'])) {
        $data = $row['Galaxy'];
    } elseif (!empty($row['AttributeTag'])) {
        $data = $row['AttributeTag'];
    } elseif (empty($addUrl)) {
        return;
    } else {
        $data = [];
    }
}

$clusters = [];
foreach ($data as $item) {
    // AttributeTag format: Tag.is_galaxy = true
    if (!empty($item['Tag']) && !empty($item['Tag']['is_galaxy'])) {
        $tagName = $item['Tag']['name'];
        preg_match('/^[^:]+:([^=]+)="(.+)"$/', $tagName, $m);
        $clusters[] = [
            'value' => isset($m[2]) ? $m[2] : $tagName,
            'galaxy' => ucwords(str_replace('-', ' ', isset($m[1]) ? $m[1] : 'unknown')),
            'tag_id' => $item['Tag']['id'] ?? null,
            'local' => !empty($item['local']),
            'relationship_type' => $item['relationship_type'] ?? null,
        ];
    } elseif (!empty($item['Galaxy'])) {
        // Galaxy / GalaxyCluster format
        $clusters[] = [
            'value' => $item['value'] ?? '',
            'galaxy' => $item['Galaxy']['name'],
            'icon' => $item['Galaxy']['icon'] ?? 'globe',
            'tag_id' => $item['tag_id'] ?? null,
            'local' => !empty($item['local']),
            'relationship_type' => $item['relationship_type'] ?? null,
            'description' => $item['description'] ?? null,
        ];
    } elseif (!empty($item['GalaxyCluster'])) {
        // A galaxy carrying its whole cluster list
        foreach ($item['GalaxyCluster'] as $gc) {
            $clusters[] = [
                'value' => $gc['value'] ?? '',
                'galaxy' => $item['name'] ?? 'Unknown',
                'icon' => $item['icon'] ?? 'globe',
                'tag_id' => $gc['tag_id'] ?? null,
                'local' => !empty($gc['local']),
                'relationship_type' => $gc['relationship_type'] ?? null,
                'description' => $gc['description'] ?? null,
            ];
        }
    }
}

// Nothing attached, nothing to relate
if (empty($clusters)) {
    $addRelationshipUrl = null;
}

/* The two inline buttons of this column share one look. */
$addBtnStyle = 'cursor:pointer; background:hsla(258,90%,66%,.12);'
             . ' color:hsl(258,55%,40%);';

$maxVisible  = 5;
$hiddenCount = max(0, count($clusters) - $maxVisible);
$noLink = function () {
    return null;
};
?>

<div class="galaxy-container d-inline-flex flex-wrap align-items-center gap-1">

<?php
echo $this->TagChip->clusters(array_slice($clusters, 0, $maxVisible), [
    'href' => $noLink,
]);
echo $this->TagChip->clusters(array_slice($clusters, $maxVisible), [
    'href' => $noLink,
    'class' => 'd-none extra-galaxies',
]);
?>

<?php if ($hiddenCount > 0): ?>
    <span
        class="badge bg-secondary text-white me-1 mb-1 galaxy-expand"
        style="cursor:pointer;"
        data-hidden="<?= $hiddenCount ?>"
        onclick="toggleGalaxies(this)"
    >
        +<?= $hiddenCount ?>
    </span>
<?php endif; ?>

<?php if (!empty($addUrl)): ?>
    <button
        type="button"
        class="badge border-0 me-1 mb-1 align-self-start attr-add-galaxy-btn"
        style="<?= $addBtnStyle ?>"
        title="<?= __('Add a galaxy cluster') ?>"
        aria-label="<?= __('Add a galaxy cluster') ?>"
        onclick="event.stopPropagation(); openModal('<?= h($addUrl) ?>', 'xl');"
    >
        <i class="fas fa-plus"></i>
    </button>
<?php endif; ?>

<?php if (!empty($addRelationshipUrl)): ?>
    <button
        type="button"
        class="badge border-0 me-1 mb-1 align-self-start attr-add-galaxy-relationship-btn"
        style="<?= $addBtnStyle ?>"
        title="<?= __('Add a relationship') ?>"
        aria-label="<?= __('Add a relationship') ?>"
        onclick="event.stopPropagation(); openModal('<?= h($addRelationshipUrl) ?>', 'xl');"
    >
        <i class="fas fa-link"></i>
    </button>
<?php endif; ?>

</div>

<script>
window.toggleGalaxies = window.toggleGalaxies || function(badge) {
    const container = badge.closest('.galaxy-container');
    const hidden = container.querySelectorAll('.extra-galaxies');
    if (!hidden.length) return;
    const isHidden = hidden[0].classList.contains('d-none');
    hidden.forEach(el => el.classList.toggle('d-none'));
    badge.textContent = isHidden ? '−' : '+' + (badge.dataset.hidden || hidden.length);
};
</script>
