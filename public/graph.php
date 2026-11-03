<?php
// Draws the commit history of all branches as an SVG graph.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
$repo = require_repo($conn);
$id = (int)$repo['id'];

$commits = all_commits($conn, $id);
$parents = parent_map($conn, $id);
$refs = list_refs($conn, $id);

// Branch names pointing at each commit
$labels = array();
foreach ($refs as $name => $hash) {
    $labels[$hash][] = (string)$name;
}

/* ---- 1) order the commits: newest first, but every commit before its parents ---- */
$waiting = array();                 // commit => number of children not placed yet
foreach ($commits as $h => $c) {
    $waiting[$h] = 0;
}
foreach ($commits as $h => $c) {
    foreach ($parents[$h] as $p) {
        if (isset($waiting[$p])) {
            $waiting[$p]++;
        }
    }
}
$ready = array();
foreach ($waiting as $h => $n) {
    if ($n == 0) {
        $ready[$h] = true;
    }
}
$order = array();
while (count($ready) > 0) {
    $best = null;
    foreach ($ready as $h => $x) {
        if ($best === null || $commits[$h]['ts'] > $commits[$best]['ts']) {
            $best = $h;
        }
    }
    unset($ready[$best]);
    $order[] = $best;
    foreach ($parents[$best] as $p) {
        if (isset($waiting[$p])) {
            $waiting[$p]--;
            if ($waiting[$p] == 0) {
                $ready[$p] = true;
            }
        }
    }
}
$total = count($order);
$order = array_slice($order, 0, 300);

/* ---- 2) give each commit a lane (a column) ---- */
$lanes = array();      // lane number => commit hash that lane is waiting for (or null)
$pos = array();        // commit => array(lane, row)
$route = array();      // "child>parent" => lane the line travels down
$max_lane = 0;

function lane_waiting_for($lanes, $hash)
{
    foreach ($lanes as $l => $expected) {
        if ($expected === $hash) {
            return $l;
        }
    }
    return null;
}
function free_lane(&$lanes)
{
    foreach ($lanes as $l => $expected) {
        if ($expected === null) {
            return $l;
        }
    }
    $lanes[] = null;
    return count($lanes) - 1;
}

foreach ($order as $row => $h) {
    $mine = array();
    foreach ($lanes as $l => $expected) {
        if ($expected === $h) {
            $mine[] = $l;
        }
    }
    if (count($mine) == 0) {
        $lane = free_lane($lanes);          // a branch tip: start a new line
    } else {
        $lane = $mine[0];
        for ($k = 1; $k < count($mine); $k++) {
            $lanes[$mine[$k]] = null;       // other lines end here (they merge into this commit)
        }
    }
    $pos[$h] = array($lane, $row);
    $max_lane = max($max_lane, $lane);

    $ps = $parents[$h];
    if (count($ps) == 0) {
        $lanes[$lane] = null;
        continue;
    }
    // first parent keeps this lane unless another line already waits for it
    $existing = lane_waiting_for($lanes, $ps[0]);
    if ($existing === null) {
        $lanes[$lane] = $ps[0];
        $route[$h . '>' . $ps[0]] = $lane;
    } else {
        $lanes[$lane] = null;
        $route[$h . '>' . $ps[0]] = $existing;
    }
    // other parents (merge commits) get their own line
    for ($i = 1; $i < count($ps); $i++) {
        $ex = lane_waiting_for($lanes, $ps[$i]);
        if ($ex === null) {
            $ex = free_lane($lanes);
            $lanes[$ex] = $ps[$i];
            $max_lane = max($max_lane, $ex);
        }
        $route[$h . '>' . $ps[$i]] = $ex;
    }
}

/* ---- 3) draw ---- */
$LS = 20;                       // space between lanes
$RH = 46;                       // row height
$x0 = 20 + ($max_lane + 1) * $LS + 16;
$height = 30 + count($order) * $RH;
$width = $x0 + 620;
$palette = array('#2457D6', '#C2410C', '#15803D', '#7C3AED', '#B45309', '#0E7490', '#BE185D', '#4D7C0F');

function lx($lane, $LS)
{
    return 20 + $lane * $LS;
}
function ry($row, $RH)
{
    return 28 + $row * $RH;
}

$svg = '';
// lines first (so the dots sit on top)
foreach ($order as $row => $h) {
    foreach ($parents[$h] as $p) {
        $rl = $route[$h . '>' . $p];
        $xc = lx($pos[$h][0], $LS);
        $yc = ry($row, $RH);
        $xr = lx($rl, $LS);
        $color = $palette[$rl % count($palette)];
        $d = 'M ' . $xc . ' ' . $yc;
        if (!isset($pos[$p])) {
            // parent is not drawn (older than the newest 300): line runs off the bottom
            if ($xr != $xc) {
                $d .= ' C ' . $xc . ' ' . ($yc + $RH / 2) . ' ' . $xr . ' ' . ($yc + $RH / 2) . ' ' . $xr . ' ' . ($yc + $RH);
            }
            $d .= ' L ' . $xr . ' ' . ($height - 10);
        } else {
            $xp = lx($pos[$p][0], $LS);
            $yp = ry($pos[$p][1], $RH);
            if ($yp - $yc <= $RH) {
                $d .= ' C ' . $xc . ' ' . ($yc + $RH / 2) . ' ' . $xp . ' ' . ($yp - $RH / 2) . ' ' . $xp . ' ' . $yp;
            } else {
                if ($xr != $xc) {
                    $d .= ' C ' . $xc . ' ' . ($yc + $RH / 2) . ' ' . $xr . ' ' . ($yc + $RH / 2) . ' ' . $xr . ' ' . ($yc + $RH);
                } else {
                    $d .= ' L ' . $xc . ' ' . ($yc + $RH);
                }
                $d .= ' L ' . $xr . ' ' . ($yp - $RH);
                if ($xr != $xp) {
                    $d .= ' C ' . $xr . ' ' . ($yp - $RH / 2) . ' ' . $xp . ' ' . ($yp - $RH / 2) . ' ' . $xp . ' ' . $yp;
                } else {
                    $d .= ' L ' . $xp . ' ' . $yp;
                }
            }
        }
        $svg .= '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="2"/>';
    }
}
// dots and text
foreach ($order as $row => $h) {
    $c = $commits[$h];
    $lane = $pos[$h][0];
    $x = lx($lane, $LS);
    $y = ry($row, $RH);
    $color = $palette[$lane % count($palette)];
    $href = e(url_to('commit.php', array('repo' => $id, 'hash' => $h)));

    if (count($parents[$h]) > 1) {
        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="6" fill="#fff" stroke="' . $color . '" stroke-width="3"/>';
    } else {
        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="5" fill="' . $color . '" stroke="#fff" stroke-width="2"/>';
    }

    $tx = $x0;
    if (isset($labels[$h])) {
        foreach ($labels[$h] as $name) {
            $w = 14 + strlen($name) * 6.8;
            $svg .= '<rect x="' . $tx . '" y="' . ($y - 17) . '" width="' . $w . '" height="18" rx="9" fill="#E8EEFB" stroke="#B9C9F2"/>';
            $svg .= '<text x="' . ($tx + 7) . '" y="' . ($y - 4) . '" font-size="11" fill="#1B3A8C" font-weight="600">' . e($name) . '</text>';
            $tx += $w + 6;
        }
    }
    $msg = $c['message'];
    if (text_length($msg) > 70) {
        $msg = (function_exists('mb_substr') ? mb_substr($msg, 0, 67, 'UTF-8') : substr($msg, 0, 67)) . '...';
    }
    $svg .= '<a href="' . $href . '"><text x="' . $tx . '" y="' . ($y - 3) . '" font-size="14" font-weight="600" fill="#17212B">' . e($msg) . '</text></a>';
    $svg .= '<text x="' . $x0 . '" y="' . ($y + 14) . '" font-size="11.5" fill="#5A6672">'
        . '<tspan font-family="ui-monospace, Menlo, Consolas, monospace">' . e(short_hash($h)) . '</tspan>  '
        . e($c['author_name']) . ', ' . e(fmt_time($c['ts'])) . '</text>';
}

$pageTitle = 'Commit graph';
$bodyClass = 'app';
require __DIR__ . '/../includes/header.php';
?>
<main class="page wide">
  <?php repo_header($repo, 'graph', $repo['default_branch']); ?>

  <?php if ($total == 0): ?>
    <div class="empty"><p class="empty-title">No commits yet</p><p>The graph appears after the first commit.</p></div>
  <?php else: ?>
    <p class="sub">Every commit of every branch. Each line is a branch of history; a hollow dot is a merge commit.
       <?php if ($total > 300): ?>Showing the newest 300 of <?= (int)$total ?> commits.<?php endif; ?></p>
    <div class="graph-wrap">
      <svg xmlns="http://www.w3.org/2000/svg" width="<?= (int)$width ?>" height="<?= (int)$height ?>"
           viewBox="0 0 <?= (int)$width ?> <?= (int)$height ?>" role="img" aria-label="Commit graph"
           font-family="'Segoe UI', 'Helvetica Neue', Arial, sans-serif"><?= $svg ?></svg>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
