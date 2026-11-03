<?php
// Object store: blobs, trees, commits and branches (refs), all kept in MySQL.
// Every object is named by the SHA-1 hash of its content, so identical content is stored only once.

/* ---------- tiny database helpers (prepared statements) ---------- */

function db_query($conn, $sql, $types = '', $params = array())
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

/** Run a SELECT and return all rows. */
function db_rows($conn, $sql, $types = '', $params = array())
{
    $stmt = db_query($conn, $sql, $types, $params);
    $res = $stmt->get_result();
    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/** Run a SELECT and return the first row (or null). */
function db_row($conn, $sql, $types = '', $params = array())
{
    $rows = db_rows($conn, $sql, $types, $params);
    return count($rows) > 0 ? $rows[0] : null;
}

/** Run INSERT/UPDATE/DELETE and return the number of affected rows. */
function db_run($conn, $sql, $types = '', $params = array())
{
    $stmt = db_query($conn, $sql, $types, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/* ---------- blobs: file contents ---------- */

function hash_blob($content)
{
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

/** Save file content (if it is not already saved) and return its hash. */
function store_blob($conn, $repo_id, $content)
{
    $hash = hash_blob($content);
    db_run($conn, 'INSERT IGNORE INTO blobs (repo_id, hash, content, size) VALUES (?, ?, ?, ?)',
        'issi', array($repo_id, $hash, $content, strlen($content)));
    return $hash;
}

function read_blob($conn, $repo_id, $hash)
{
    $r = db_row($conn, 'SELECT content FROM blobs WHERE repo_id = ? AND hash = ?', 'is', array($repo_id, $hash));
    return $r ? $r['content'] : null;
}

function blob_size($conn, $repo_id, $hash)
{
    $r = db_row($conn, 'SELECT size FROM blobs WHERE repo_id = ? AND hash = ?', 'is', array($repo_id, $hash));
    return $r ? (int)$r['size'] : 0;
}

/* ---------- trees: folders ---------- */

/**
 * Save a folder. $entries is a list of array('name', 'type' => 'blob'|'tree', 'hash', 'mode').
 * The folder hash depends on its entries, so a changed file changes every folder above it.
 */
function store_tree($conn, $repo_id, $entries)
{
    usort($entries, function ($x, $y) {
        return strcmp($x['name'], $y['name']);
    });
    $body = '';
    foreach ($entries as $en) {
        $body .= $en['mode'] . ' ' . $en['type'] . ' ' . $en['hash'] . ' ' . $en['name'] . "\n";
    }
    $hash = sha1('tree ' . strlen($body) . "\0" . $body);

    $new = db_run($conn, 'INSERT IGNORE INTO trees (repo_id, hash) VALUES (?, ?)', 'is', array($repo_id, $hash));
    if ($new > 0) {   // only write the entries the first time we see this folder
        foreach ($entries as $en) {
            db_run($conn,
                'INSERT IGNORE INTO tree_entries (repo_id, tree_hash, name, mode, type, object_hash) VALUES (?, ?, ?, ?, ?, ?)',
                'isssss', array($repo_id, $hash, $en['name'], $en['mode'], $en['type'], $en['hash']));
        }
    }
    return $hash;
}

/** Entries of a folder as a map: name => array(name, mode, type, object_hash). */
function read_tree($conn, $repo_id, $hash)
{
    static $cache = array();
    $key = $repo_id . ':' . $hash;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $rows = db_rows($conn,
        'SELECT name, mode, type, object_hash FROM tree_entries WHERE repo_id = ? AND tree_hash = ?',
        'is', array($repo_id, $hash));
    $map = array();
    foreach ($rows as $r) {
        $map[$r['name']] = $r;
    }
    $cache[$key] = $map;
    return $map;
}

/** Build all folders from a flat list (path => blob hash). Returns the hash of the root folder. */
function build_tree($conn, $repo_id, $files)
{
    $root = array();
    foreach ($files as $path => $hash) {
        $parts = explode('/', (string)$path);
        $node = &$root;
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $key = 'd:' . $parts[$i];
            if (!isset($node[$key])) {
                $node[$key] = array();
            }
            $node = &$node[$key];
        }
        $node['f:' . $parts[count($parts) - 1]] = $hash;
        unset($node);
    }
    return write_tree_node($conn, $repo_id, $root);
}

function write_tree_node($conn, $repo_id, $node)
{
    $entries = array();
    foreach ($node as $key => $val) {
        $name = substr($key, 2);
        if (substr($key, 0, 2) === 'd:') {
            $entries[] = array('name' => $name, 'type' => 'tree', 'mode' => '040000',
                'hash' => write_tree_node($conn, $repo_id, $val));
        } else {
            $entries[] = array('name' => $name, 'type' => 'blob', 'mode' => '100644', 'hash' => $val);
        }
    }
    return store_tree($conn, $repo_id, $entries);
}

/** All files under a folder as a flat map: path => blob hash. */
function flatten_tree($conn, $repo_id, $tree_hash, $prefix = '')
{
    $files = array();
    if ($tree_hash === null) {
        return $files;
    }
    foreach (read_tree($conn, $repo_id, $tree_hash) as $name => $en) {
        if ($en['type'] === 'tree') {
            $files += flatten_tree($conn, $repo_id, $en['object_hash'], $prefix . $en['name'] . '/');
        } else {
            $files[$prefix . $en['name']] = $en['object_hash'];
        }
    }
    return $files;
}

/** Follow a path ("src/css") down from a root folder. Returns array(type, hash) or null. */
function tree_at_path($conn, $repo_id, $root_hash, $path)
{
    if ($path === '') {
        return array('type' => 'tree', 'hash' => $root_hash);
    }
    $current = array('type' => 'tree', 'hash' => $root_hash);
    foreach (explode('/', $path) as $part) {
        if ($current['type'] !== 'tree') {
            return null;
        }
        $entries = read_tree($conn, $repo_id, $current['hash']);
        if (!isset($entries[$part])) {
            return null;
        }
        $current = array('type' => $entries[$part]['type'], 'hash' => $entries[$part]['object_hash']);
    }
    return $current;
}

/**
 * Compare two folders. Whole folders with the same hash are skipped without looking inside.
 * Fills $out with array('path', 'status' => added|modified|deleted, 'old' => blob hash, 'new' => blob hash).
 */
function diff_trees($conn, $repo_id, $a, $b, $prefix, &$out)
{
    if ($a === $b) {
        return;   // same hash means identical content
    }
    $ea = ($a !== null) ? read_tree($conn, $repo_id, $a) : array();
    $eb = ($b !== null) ? read_tree($conn, $repo_id, $b) : array();
    $names = array_unique(array_merge(array_keys($ea), array_keys($eb)));
    sort($names, SORT_STRING);

    foreach ($names as $name) {
        $name = (string)$name;
        $x = isset($ea[$name]) ? $ea[$name] : null;
        $y = isset($eb[$name]) ? $eb[$name] : null;
        $xt = $x ? $x['type'] : '';
        $yt = $y ? $y['type'] : '';
        if ($x && $y && $xt === $yt && $x['object_hash'] === $y['object_hash']) {
            continue;   // unchanged file or unchanged folder
        }
        $path = $prefix . $name;

        if ($xt === 'tree' || $yt === 'tree') {
            diff_trees($conn, $repo_id, $xt === 'tree' ? $x['object_hash'] : null,
                $yt === 'tree' ? $y['object_hash'] : null, $path . '/', $out);
            if ($xt === 'blob') {
                $out[] = array('path' => $path, 'status' => 'deleted', 'old' => $x['object_hash'], 'new' => null);
            }
            if ($yt === 'blob') {
                $out[] = array('path' => $path, 'status' => 'added', 'old' => null, 'new' => $y['object_hash']);
            }
        } elseif ($x && $y) {
            $out[] = array('path' => $path, 'status' => 'modified', 'old' => $x['object_hash'], 'new' => $y['object_hash']);
        } elseif ($x) {
            $out[] = array('path' => $path, 'status' => 'deleted', 'old' => $x['object_hash'], 'new' => null);
        } else {
            $out[] = array('path' => $path, 'status' => 'added', 'old' => null, 'new' => $y['object_hash']);
        }
    }
}

/* ---------- commits ---------- */

/** Save a commit. $parents is a list of commit hashes (0 = first commit, 2 = merge). */
function store_commit($conn, $repo_id, $tree_hash, $author_id, $message, $parents, $ts)
{
    $body = 'tree ' . $tree_hash . "\n";
    foreach ($parents as $p) {
        $body .= 'parent ' . $p . "\n";
    }
    $body .= 'author ' . $author_id . ' ' . $ts . "\n\n" . $message;
    $hash = sha1('commit ' . strlen($body) . "\0" . $body);

    db_run($conn,
        'INSERT INTO commits (repo_id, hash, tree_hash, author_id, message, created_at) VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))',
        'issisi', array($repo_id, $hash, $tree_hash, $author_id, $message, $ts));
    $order = 0;
    foreach ($parents as $p) {
        db_run($conn, 'INSERT INTO commit_parents (repo_id, commit_hash, parent_hash, ord) VALUES (?, ?, ?, ?)',
            'issi', array($repo_id, $hash, $p, $order));
        $order++;
    }
    return $hash;
}

function read_commit($conn, $repo_id, $hash)
{
    return db_row($conn,
        'SELECT c.hash, c.tree_hash, c.author_id, c.message, UNIX_TIMESTAMP(c.created_at) AS ts, u.name AS author_name
           FROM commits c JOIN users u ON u.id = c.author_id
          WHERE c.repo_id = ? AND c.hash = ?',
        'is', array($repo_id, $hash));
}

/** Find a commit from a full hash or a short prefix (at least 4 characters). */
function find_commit($conn, $repo_id, $prefix)
{
    if (!preg_match('/^[0-9a-f]{4,40}$/', $prefix)) {
        return null;
    }
    $rows = db_rows($conn, 'SELECT hash FROM commits WHERE repo_id = ? AND hash LIKE ? LIMIT 2',
        'is', array($repo_id, $prefix . '%'));
    return count($rows) === 1 ? $rows[0]['hash'] : null;
}

/** Every commit of the repository, newest first. */
function all_commits($conn, $repo_id)
{
    $rows = db_rows($conn,
        'SELECT c.hash, c.tree_hash, c.message, UNIX_TIMESTAMP(c.created_at) AS ts, u.name AS author_name
           FROM commits c JOIN users u ON u.id = c.author_id
          WHERE c.repo_id = ? ORDER BY c.created_at DESC, c.hash LIMIT 2000',
        'i', array($repo_id));
    $map = array();
    foreach ($rows as $r) {
        $map[$r['hash']] = $r;
    }
    return $map;
}

/** Map: commit hash => list of parent hashes (first parent first). */
function parent_map($conn, $repo_id)
{
    $map = array();
    foreach (db_rows($conn, 'SELECT hash FROM commits WHERE repo_id = ?', 'i', array($repo_id)) as $r) {
        $map[$r['hash']] = array();
    }
    $rows = db_rows($conn,
        'SELECT commit_hash, parent_hash FROM commit_parents WHERE repo_id = ? ORDER BY commit_hash, ord',
        'i', array($repo_id));
    foreach ($rows as $r) {
        $map[$r['commit_hash']][] = $r['parent_hash'];
    }
    return $map;
}

/** The commit itself and every commit before it: set of hashes. */
function ancestors($parents, $start)
{
    $seen = array($start => true);
    $stack = array($start);
    while (count($stack) > 0) {
        $h = array_pop($stack);
        if (!isset($parents[$h])) {
            continue;
        }
        foreach ($parents[$h] as $p) {
            if (!isset($seen[$p])) {
                $seen[$p] = true;
                $stack[] = $p;
            }
        }
    }
    return $seen;
}

/** Nearest commit that both $a and $b descend from (the merge base), or null. */
function merge_base($parents, $a, $b)
{
    $anc = ancestors($parents, $a);
    $queue = array($b);
    $seen = array($b => true);
    while (count($queue) > 0) {
        $h = array_shift($queue);
        if (isset($anc[$h])) {
            return $h;
        }
        foreach ($parents[$h] as $p) {
            if (!isset($seen[$p])) {
                $seen[$p] = true;
                $queue[] = $p;
            }
        }
    }
    return null;
}

/* ---------- refs: branches ---------- */

function get_ref($conn, $repo_id, $name)
{
    $r = db_row($conn, 'SELECT commit_hash FROM refs WHERE repo_id = ? AND name = ?', 'is', array($repo_id, $name));
    return $r ? $r['commit_hash'] : null;
}

/** Create the branch or move it to another commit. */
function set_ref($conn, $repo_id, $name, $hash)
{
    db_run($conn,
        'INSERT INTO refs (repo_id, name, commit_hash) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE commit_hash = ?',
        'isss', array($repo_id, $name, $hash, $hash));
}

function delete_ref($conn, $repo_id, $name)
{
    return db_run($conn, 'DELETE FROM refs WHERE repo_id = ? AND name = ?', 'is', array($repo_id, $name));
}

/** All branches: name => commit hash. */
function list_refs($conn, $repo_id)
{
    $out = array();
    foreach (db_rows($conn, 'SELECT name, commit_hash FROM refs WHERE repo_id = ? ORDER BY name', 'i', array($repo_id)) as $r) {
        $out[$r['name']] = $r['commit_hash'];
    }
    return $out;
}

/** A branch name or a (short) commit hash. Returns array('hash', 'is_branch') or null. */
function resolve_ref($conn, $repo_id, $ref)
{
    $h = get_ref($conn, $repo_id, $ref);
    if ($h !== null) {
        return array('hash' => $h, 'is_branch' => true);
    }
    $c = find_commit($conn, $repo_id, $ref);
    if ($c !== null) {
        return array('hash' => $c, 'is_branch' => false);
    }
    return null;
}
