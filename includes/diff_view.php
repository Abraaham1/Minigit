<?php
// Draws file differences as HTML (red removed lines, green added lines).

/**
 * $files: list of entries from make_file_diff().
 * $with_checkbox: when true, each file has a checkbox (selected[]) for the review page.
 */
function render_file_diffs($files, $with_checkbox = false)
{
    foreach ($files as $f) {
        echo '<section class="filediff">';
        echo '<header class="filediff-head">';
        if ($with_checkbox) {
            echo '<label class="pick"><input type="checkbox" name="selected[]" value="' . e($f['path']) . '" checked>'
                . '<span class="sr">Include ' . e($f['path']) . '</span></label>';
        }
        echo '<span class="badge badge-' . e($f['status']) . '">' . e(ucfirst($f['status'])) . '</span>';
        echo '<span class="filepath">' . e($f['path']) . '</span>';
        echo '<span class="counts"><span class="c-add">+' . (int)$f['added'] . '</span> <span class="c-del">-' . (int)$f['removed'] . '</span></span>';
        echo '</header>';

        if ($f['binary']) {
            echo '<p class="diff-note">Binary file. Lines are not compared.</p>';
        } elseif ($f['big']) {
            echo '<p class="diff-note">This file is too large to show line by line.</p>';
        } elseif (count($f['ops']) == 0) {
            echo '<p class="diff-note">' . ($f['status'] === 'added' ? 'Empty file.' : 'No line changes.') . '</p>';
        } else {
            echo '<div class="diff-scroll"><table class="diff"><tbody>';
            foreach ($f['ops'] as $op) {
                if ($op['t'] === '~') {
                    echo '<tr class="fold"><td colspan="4">' . (int)$op['n'] . ' unchanged lines</td></tr>';
                    continue;
                }
                $cls = ($op['t'] === '+') ? 'add' : (($op['t'] === '-') ? 'del' : 'ctx');
                $sign = ($op['t'] === '=') ? ' ' : $op['t'];
                echo '<tr class="' . $cls . '">'
                    . '<td class="ln">' . ($op['a'] ? (int)$op['a'] : '') . '</td>'
                    . '<td class="ln">' . ($op['b'] ? (int)$op['b'] : '') . '</td>'
                    . '<td class="sign">' . e($sign) . '</td>'
                    . '<td class="code">' . e($op['text']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }
}
