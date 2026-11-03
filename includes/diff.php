<?php
// Line by line diff and three way merge.
// These are plain functions with no database access, so they are easy to test.

/** Split text into an array of lines (handles \r\n, ignores one final newline). */
function split_lines($text)
{
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    if ($text === '') {
        return array();
    }
    $lines = explode("\n", $text);
    if ($lines[count($lines) - 1] === '') {
        array_pop($lines);   // text ended with a newline
    }
    return $lines;
}

/** True if the data looks like a binary file (contains a NUL byte). */
function is_binary($data)
{
    return strpos(substr($data, 0, 8000), "\0") !== false;
}

/**
 * Compare two arrays of lines.
 * Returns a list of operations: array('t' => '=' | '+' | '-', 'text' => line, 'a' => old line no, 'b' => new line no)
 */
function diff_lines($a, $b)
{
    $n = count($a);
    $m = count($b);

    // 1) Skip the lines that are equal at the start and at the end (most edits touch a small area)
    $start = 0;
    while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
        $start++;
    }
    $endA = $n;
    $endB = $m;
    while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
        $endA--;
        $endB--;
    }

    // 2) Run the longest common subsequence only on the middle part
    $ops = array();
    for ($i = 0; $i < $start; $i++) {
        $ops[] = array('t' => '=', 'text' => $a[$i]);
    }
    $middle = diff_middle(array_slice($a, $start, $endA - $start), array_slice($b, $start, $endB - $start));
    foreach ($middle as $op) {
        $ops[] = $op;
    }
    for ($i = $endA; $i < $n; $i++) {
        $ops[] = array('t' => '=', 'text' => $a[$i]);
    }

    // 3) Add line numbers
    $ia = 0;
    $ib = 0;
    foreach ($ops as $k => $op) {
        if ($op['t'] === '=') {
            $ia++; $ib++;
            $ops[$k]['a'] = $ia; $ops[$k]['b'] = $ib;
        } elseif ($op['t'] === '-') {
            $ia++;
            $ops[$k]['a'] = $ia; $ops[$k]['b'] = 0;
        } else {
            $ib++;
            $ops[$k]['a'] = 0; $ops[$k]['b'] = $ib;
        }
    }
    return $ops;
}

/** Longest common subsequence on the changed middle part. */
function diff_middle($a, $b)
{
    $n = count($a);
    $m = count($b);
    $ops = array();

    // Nothing to compare, or too big for the table: treat everything as replaced
    if ($n == 0 || $m == 0 || $n * $m > 1000000) {
        foreach ($a as $line) {
            $ops[] = array('t' => '-', 'text' => $line);
        }
        foreach ($b as $line) {
            $ops[] = array('t' => '+', 'text' => $line);
        }
        return $ops;
    }

    // dp[i][j] = length of the common part of a[i..] and b[j..]
    $dp = array();
    for ($i = 0; $i <= $n; $i++) {
        $dp[$i] = array_fill(0, $m + 1, 0);
    }
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            if ($a[$i] === $b[$j]) {
                $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
            } else {
                $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
    }

    // Walk through the table to produce the operations
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $ops[] = array('t' => '=', 'text' => $a[$i]);
            $i++; $j++;
        } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $ops[] = array('t' => '-', 'text' => $a[$i]);
            $i++;
        } else {
            $ops[] = array('t' => '+', 'text' => $b[$j]);
            $j++;
        }
    }
    while ($i < $n) {
        $ops[] = array('t' => '-', 'text' => $a[$i]);
        $i++;
    }
    while ($j < $m) {
        $ops[] = array('t' => '+', 'text' => $b[$j]);
        $j++;
    }
    return $ops;
}

/** Keep only the changed lines plus $ctx lines around them; replace the rest by a '~' row. */
function fold_context($ops, $ctx)
{
    $n = count($ops);
    $keep = array_fill(0, $n, false);
    for ($i = 0; $i < $n; $i++) {
        if ($ops[$i]['t'] !== '=') {
            $from = max(0, $i - $ctx);
            $to = min($n - 1, $i + $ctx);
            for ($k = $from; $k <= $to; $k++) {
                $keep[$k] = true;
            }
        }
    }
    $out = array();
    $skipped = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($keep[$i]) {
            if ($skipped > 0) {
                $out[] = array('t' => '~', 'n' => $skipped);
                $skipped = 0;
            }
            $out[] = $ops[$i];
        } else {
            $skipped++;
        }
    }
    if ($skipped > 0 && count($out) > 0) {
        $out[] = array('t' => '~', 'n' => $skipped);
    }
    return $out;
}

/** Count added and removed lines. */
function diff_stats($ops)
{
    $added = 0;
    $removed = 0;
    foreach ($ops as $op) {
        if ($op['t'] === '+') {
            $added++;
        } elseif ($op['t'] === '-') {
            $removed++;
        }
    }
    return array($added, $removed);
}

/**
 * Turn a diff into "hunks": each hunk says "base lines [bs, be) were replaced by these lines".
 * An insertion has bs == be.
 */
function change_hunks($base, $other)
{
    $ops = diff_lines($base, $other);
    $hunks = array();
    $cur = null;
    $i = 0;   // position in $base
    foreach ($ops as $op) {
        if ($op['t'] === '=') {
            if ($cur !== null) {
                $hunks[] = $cur;
                $cur = null;
            }
            $i++;
        } elseif ($op['t'] === '-') {
            if ($cur === null) {
                $cur = array('bs' => $i, 'be' => $i, 'lines' => array());
            }
            $cur['be']++;
            $i++;
        } else {
            if ($cur === null) {
                $cur = array('bs' => $i, 'be' => $i, 'lines' => array());
            }
            $cur['lines'][] = $op['text'];
        }
    }
    if ($cur !== null) {
        $hunks[] = $cur;
    }
    return $hunks;
}

/** The lines one side produces for the base region [s, e) after applying its hunks. */
function side_text($base, $hunks, $s, $e)
{
    $res = array();
    $p = $s;
    foreach ($hunks as $h) {
        while ($p < $h['bs']) {
            $res[] = $base[$p];
            $p++;
        }
        foreach ($h['lines'] as $line) {
            $res[] = $line;
        }
        $p = $h['be'];
    }
    while ($p < $e) {
        $res[] = $base[$p];
        $p++;
    }
    return $res;
}

/**
 * Three way merge of line arrays.
 * $base is the common ancestor, $ours and $theirs are the two changed versions.
 * Changes that do not overlap are combined. Overlapping different changes become conflict markers.
 * Returns array('lines' => merged lines, 'conflicts' => number of conflicts).
 */
function merge3($base, $ours, $theirs, $our_label, $their_label)
{
    $ho = change_hunks($base, $ours);
    $ht = change_hunks($base, $theirs);
    $no = count($ho);
    $nt = count($ht);
    $out = array();
    $conflicts = 0;
    $pos = 0;
    $io = 0;
    $it = 0;

    while ($io < $no || $it < $nt) {
        // Where does the next change start?
        if ($io < $no && ($it >= $nt || $ho[$io]['bs'] <= $ht[$it]['bs'])) {
            $s = $ho[$io]['bs'];
        } else {
            $s = $ht[$it]['bs'];
        }
        // Copy the untouched base lines before it
        while ($pos < $s) {
            $out[] = $base[$pos];
            $pos++;
        }

        // Gather every change (from both sides) that touches the same area
        $e = $s;
        $co = array();
        $ct = array();
        $joined = true;
        while ($joined) {
            $joined = false;
            if ($io < $no && $ho[$io]['bs'] <= $e) {
                $co[] = $ho[$io];
                $e = max($e, $ho[$io]['be']);
                $io++;
                $joined = true;
            }
            if ($it < $nt && $ht[$it]['bs'] <= $e) {
                $ct[] = $ht[$it];
                $e = max($e, $ht[$it]['be']);
                $it++;
                $joined = true;
            }
        }

        $mine = side_text($base, $co, $s, $e);
        $other = side_text($base, $ct, $s, $e);

        if (count($ct) == 0) {
            $chosen = $mine;
        } elseif (count($co) == 0) {
            $chosen = $other;
        } elseif ($mine === $other) {
            $chosen = $mine;          // both sides made the same change
        } else {
            $conflicts++;
            $chosen = array('<<<<<<< ' . $our_label);
            foreach ($mine as $line) { $chosen[] = $line; }
            $chosen[] = '=======';
            foreach ($other as $line) { $chosen[] = $line; }
            $chosen[] = '>>>>>>> ' . $their_label;
        }
        foreach ($chosen as $line) {
            $out[] = $line;
        }
        $pos = $e;
    }

    while ($pos < count($base)) {
        $out[] = $base[$pos];
        $pos++;
    }
    return array('lines' => $out, 'conflicts' => $conflicts);
}

/** Three way merge of whole texts. Returns array('text' => merged text, 'conflicts' => n). */
function merge_text($base, $ours, $theirs, $our_label, $their_label)
{
    $r = merge3(split_lines($base), split_lines($ours), split_lines($theirs), $our_label, $their_label);
    $text = implode("\n", $r['lines']);
    if (count($r['lines']) > 0 && (substr($ours, -1) === "\n" || substr($theirs, -1) === "\n")) {
        $text .= "\n";
    }
    return array('text' => $text, 'conflicts' => $r['conflicts']);
}

/** True if text still contains conflict marker lines. */
function has_conflict_markers($text)
{
    return preg_match('/^(<<<<<<< |=======$|>>>>>>> )/m', str_replace("\r\n", "\n", $text)) === 1;
}
