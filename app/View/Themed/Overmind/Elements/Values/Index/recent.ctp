<?php
/**
 * Carried over — the values this reader last opened.
 *
 * The one block on this page whose rows are neither the reader's paste
 * nor an aggregate over `attributes`: it reads ten strings out of one
 * `user_settings` row that this reader put there by opening them
 * (`value-index.md` §7.4).
 *
 * **Yours alone, and it says so.** D27 refuses to name who ran an
 * enrichment because naming the runner tells the organisation which
 * colleague is looking at which value; a recently-viewed list anybody
 * else could read is that disclosure with a different label. The
 * sentence in the card's header is not reassurance — it is the reason a
 * reader can use the block without thinking about who is watching.
 *
 * **No lean on an entry.** Ten assessments is ten engine runs spent
 * before the reader has pasted anything, on a block nobody has asked a
 * question of yet (`02a-contract.md` §12.7). The hover card answers
 * for the value under the cursor, at the moment there is one, and
 * costs nothing until then — so each entry is a hover trigger where the
 * instance has the card on, and a plain link where it does not.
 *
 * An empty one is a sentence in the future tense rather than an empty
 * list, so a new install does not read as broken.
 *
 * @var array<array{value: string, at: int}> $recent Newest first
 */
App::uses('ValueUrlTool', 'Tools/ValueIntelligence');
/*
 * `CakeTime` and not `$this->Time`. MISP replaces CakePHP's
 * `TimeHelper` wholesale with a two-method helper of its own — `time()`
 * and `date()` — so `$this->Time->timeAgoInWords()` reaches
 * `Helper::__call`, returns the empty string, and renders a chip with a
 * blank where the age goes. Nothing throws and the page looks finished.
 */
App::uses('CakeTime', 'Utility');

$hoverCard = (bool)Configure::read('MISP.value_hover_card');
?>
<section class="card shadow-sm vi-rcard vi-recent" aria-labelledby="vi-rcard-recent">
    <header class="vi-rcard-h">
        <span class="vi-rcard-ico" aria-hidden="true"><i class="fas fa-clock-rotate-left"></i></span>
        <h2 class="vi-rcard-t" id="vi-rcard-recent"><?= h(__('Recently opened')) ?></h2>
        <span class="vi-rcard-sub"><?= h(__('Only you can see this list.')) ?></span>
    </header>
<?php if (empty($recent)): ?>
    <p class="vi-recent__lead"><?= h(__('Values you open will be listed here.')) ?></p>
<?php else: ?>
    <ul class="vi-recent__list">
<?php   foreach ($recent as $entry): ?>
        <li><a class="vi-chip<?= $hoverCard ? ' vp-hc-trigger' : '' ?>"
           href="<?= h($this->Html->url(array(
               'controller' => 'values',
               'action' => 'view',
               ValueUrlTool::address($entry['value']),
           ))) ?>"
<?php       if ($hoverCard): ?>
           data-vp-hc-value="<?= h(ValueUrlTool::address($entry['value'])) ?>"
<?php       endif; ?>
           ><span class="vi-chip__value"><?= h($entry['value']) ?></span>
            <span class="vi-chip__at"><?= h(
                CakeTime::timeAgoInWords($entry['at'], array(
                    /*
                     * One unit rather than two past a day: the hour is
                     * not what a reader scanning this list is asking
                     * about. Below a day the default is already one unit.
                     */
                    'accuracy' => array('day' => 'day'),
                ))
            ) ?></span></a></li>
<?php   endforeach; ?>
    </ul>
<?php endif; ?>
</section>
