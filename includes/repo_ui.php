<?php
// Helpers for the repository pages: header, tabs, branch selector, formatting.

function url_to($page, $params = array())
{
    return $page . (count($params) > 0 ? '?' . http_build_query($params) : '');
}

function short_hash($hash)
{
    return substr($hash, 0, 7);
}

function fmt_time($ts)
{
    return date('j M Y, g:i a', (int)$ts);
}

function human_size($bytes)
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/** Show a simple message page and stop (used for "not found" and similar). */
function stop_with_message($code, $title, $message)
{
    http_response_code($code);
    $pageTitle = $title;
    $bodyClass = 'app';
    require __DIR__ . '/header.php';
    echo '<main class="page"><h1>' . e($title) . '</h1><p>' . e($message) . '</p>'
        . '<p><a href="home.php">Back to your repositories</a></p></main>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Load the repository named in ?repo= (or the POST field), only if it belongs to the logged in user. */
function require_repo($conn)
{
    $id = 0;
    if (isset($_POST['repo'])) {
        $id = (int)$_POST['repo'];
    } elseif (isset($_GET['repo'])) {
        $id = (int)$_GET['repo'];
    }
    $repo = ($id > 0) ? user_repo($conn, $id, $_SESSION['user_id']) : null;
    if (!$repo) {
        stop_with_message(404, 'Repository not found', 'It does not exist, or it belongs to another account.');
    }
    return $repo;
}

/** Repository title and tabs. $active is one of: code, commits, branches, graph, compare, settings. */
function repo_header($repo, $active, $ref)
{
    $id = (int)$repo['id'];
    $tabs = array(
        'code'     => array('Code', url_to('repo.php', array('repo' => $id, 'ref' => $ref))),
        'commits'  => array('Commits', url_to('commits.php', array('repo' => $id, 'ref' => $ref))),
        'branches' => array('Branches', url_to('branches.php', array('repo' => $id))),
        'graph'    => array('Graph', url_to('graph.php', array('repo' => $id))),
        'compare'  => array('Compare', url_to('compare.php', array('repo' => $id))),
        'settings' => array('Settings', url_to('settings.php', array('repo' => $id))),
    );
    echo '<div class="repo-head">';
    echo '<h1 class="repo-title"><a href="home.php">Repositories</a> <span class="slash">/</span> ' . e($repo['name']) . '</h1>';
    if ($repo['description'] !== null && $repo['description'] !== '') {
        echo '<p class="repo-desc">' . e($repo['description']) . '</p>';
    }
    echo '<nav class="tabs">';
    foreach ($tabs as $key => $tab) {
        echo '<a href="' . e($tab[1]) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . e($tab[0]) . '</a>';
    }
    echo '</nav></div>';
}

/** A small form with a branch dropdown that reloads the page when changed. */
function branch_select($action, $repo, $refs, $current, $hidden = array())
{
    echo '<form method="get" action="' . e($action) . '" class="branch-select">';
    echo '<input type="hidden" name="repo" value="' . (int)$repo['id'] . '">';
    foreach ($hidden as $k => $v) {
        echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    echo '<label for="ref-select" class="sr">Branch</label>';
    echo '<select id="ref-select" name="ref" data-autosubmit>';
    $found = false;
    foreach ($refs as $name => $hash) {
        $sel = ((string)$name === $current) ? ' selected' : '';
        if ($sel !== '') {
            $found = true;
        }
        echo '<option value="' . e($name) . '"' . $sel . '>' . e($name) . '</option>';
    }
    if (!$found && $current !== '') {
        echo '<option value="' . e($current) . '" selected>' . e(short_hash($current)) . ' (commit)</option>';
    }
    echo '</select><noscript><button class="btn btn-quiet" type="submit">Go</button></noscript></form>';
}

/** Breadcrumb links for a path inside the repository. */
function breadcrumbs($repo, $ref, $path)
{
    $id = (int)$repo['id'];
    echo '<p class="crumbs"><a href="' . e(url_to('repo.php', array('repo' => $id, 'ref' => $ref))) . '">' . e($repo['name']) . '</a>';
    if ($path !== '') {
        $acc = '';
        $parts = explode('/', $path);
        foreach ($parts as $i => $part) {
            $acc .= ($i > 0 ? '/' : '') . $part;
            echo ' / ';
            if ($i < count($parts) - 1) {
                echo '<a href="' . e(url_to('repo.php', array('repo' => $id, 'ref' => $ref, 'path' => $acc))) . '">' . e($part) . '</a>';
            } else {
                echo '<strong>' . e($part) . '</strong>';
            }
        }
    }
    echo '</p>';
}
