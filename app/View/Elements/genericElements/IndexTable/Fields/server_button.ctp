<?php
if (empty($field['button']) || empty($field['button']['js_action'])) {
    return;
}

$button = $field['button'];

// Helper pour résoudre Server.id, etc.
$resolvePath = function ($path) use ($row) {
    $value = $row;
    foreach (explode('.', $path) as $p) {
        if (!isset($value[$p])) {
            return null;
        }
        $value = $value[$p];
    }
    return $value;
};

// ID primaire
$idValue = null;
if (!empty($button['cell_id_param_path'])) {
    $idValue = $resolvePath($button['cell_id_param_path']);
}

// ID du conteneur cellule
$containerId = null;
if (!empty($button['cell_id']) && $idValue !== null) {
    $containerId = sprintf($button['cell_id'], $idValue);
}

// Paramètre JS
$jsParam = !empty($button['js_param_path'])
    ? $resolvePath($button['js_param_path'])
    : null;

// Valeurs bouton
$label = $button['label'] ?? '';
$class = $button['class'] ?? 'btn btn-primary';
$style = $button['style'] ?? '';
$title = $button['title'] ?? '';
$ariaLabel = $button['aria_label'] ?? $title;
$jsAction = $button['js_action'];

$onClick = $jsParam !== null
    ? sprintf("%s('%s');", $jsAction, h($jsParam))
    : sprintf("%s();", $jsAction);

// 🔥 RENDU FINAL
echo sprintf(
    '<div%s>
        <span role="button"
              tabindex="0"
              class="%s"
              style="%s"
              title="%s"
              aria-label="%s"
              onClick="%s">%s</span>
     </div>',
    $containerId ? ' id="' . h($containerId) . '"' : '',
    h($class),
    h($style),
    h($title),
    h($ariaLabel),
    $onClick,
    h($label)
);
