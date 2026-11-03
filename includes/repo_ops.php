<?php
// High level operations: repositories, staging area, commits, branches, merges and diffs.
// They use the diff functions (diff.php) and the object store (objects.php).

// An error whose message is safe to show to the user
class UserError extends Exception
{
}

/* ---------- names and paths ---------- */

function valid_repo_name($name)
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name) === 1;
}

function valid_branch_name($name)
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,98}$/', $name)) {
        return false;
    }
    if (strpos($name, '..') !== false || strpos($name, '//') !== false) {
        return false;
    }
    if (substr($name, -1) === '/' || substr($name, -1) === '.') {
        return false;
    }
    return true;
}

/** Clean a file path typed by a user. Returns the cleaned path, '' for the root, or false if unsafe. */
function clean_path($p)
{
    $p = str_replace('\\', '/', trim($p));
    $p = trim($p, '/');
    if ($p === '') {
        return '';
    }
    if (strlen($p) > 1000) {
        return false;
    }
    foreach (explode('/', $p) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
        if (strlen($part) > 255) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $part)) {
            return false;
        }
    }
    return $p;
}

/** A file and a folder cannot have the same path. Returns an error text or ''. */
function path_conflict($files)
{
    foreach ($files as $path => $hash) {
        $parts = explode('/', (string)$path);
        $acc = '';
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $acc .= ($i > 0 ? '/' : '') . $parts[$i];
            if (isset($files[$acc])) {
                return "'" . $acc . "' is a file, so it cannot also be a folder.";
            }
        }
    }
    return '';
}

/* ---------- repositories ---------- */

/** The repository, only if it belongs to this user. */
function user_repo($conn, $repo_id, $user_id)
{
    return db_row($conn,
        'SELECT id, owner_id, name, description, default_branch, UNIX_TIMESTAMP(created_at) AS ts
           FROM repos WHERE id = ? AND owner_id = ?',
        'ii', array($repo_id, $user_id));
}

/** Create a repository. Returns the new id. Throws UserError for bad input. */
function create_repo($conn, $owner_id, $name, $description)
{
    if (!valid_repo_name($name)) {
        throw new UserError('Use 1 to 100 letters, numbers, dots, dashes or underscores, starting with a letter or number.');
    }
    if (text_length($description) > 255) {
        throw new UserError('The description must be 255 characters or fewer.');
    }
    $exists = db_row($conn, 'SELECT id FROM repos WHERE owner_id = ? AND name = ?', 'is', array($owner_id, $name));
    if ($exists) {
        throw new UserError('You already have a repository with that name.');
    }
    try {
        db_run($conn, 'INSERT INTO repos (owner_id, name, description, default_branch) VALUES (?, ?, ?, ?)',
            'isss', array($owner_id, $name, $description === '' ? null : $description, 'main'));
    } catch (mysqli_sql_exception $ex) {
        if ((int)$ex->getCode() === 1062) {
            throw new UserError('You already have a repository with that name.');
        }
        throw $ex;
    }
    return (int)$conn->insert_id;
}

function delete_repo($conn, $repo_id, $owner_id)
{
    // Foreign keys with ON DELETE CASCADE remove blobs, trees, commits and branches too
    return db_run($conn, 'DELETE FROM repos WHERE id = ? AND owner_id = ?', 'ii', array($repo_id, $owner_id));
}

/** Lock the repository row so two commits or merges cannot run at the same time. */
function lock_repo($conn, $repo_id)
{
    db_row($conn, 'SELECT id FROM repos WHERE id = ? FOR UPDATE', 'i', array($repo_id));
}

/** Flat file map (path => blob hash) of a commit. A null commit means an empty project. */
function files_at_commit($conn, $repo_id, $commit_hash)
{
    if ($commit_hash === null) {
        return array();
    }
    $c = read_commit($conn, $repo_id, $commit_hash);
    return $c ? flatten_tree($conn, $repo_id, $c['tree_hash']) : array();
}

/* ---------- staging area (changes waiting for review, kept in the session) ---------- */

define('STAGE_MAX_BYTES', 6 * 1024 * 1024);

function stage_get($repo_id, $branch)
{
    if (isset($_SESSION['stage'][$repo_id][$branch])) {
        return $_SESSION['stage'][$repo_id][$branch];
    }
    return null;
}

function stage_save($repo_id, $branch, $stage)
{
    $_SESSION['stage'][$repo_id][$branch] = $stage;
}

function stage_clear($repo_id, $branch)
{
    unset($_SESSION['stage'][$repo_id][$branch]);
}

/** Start a staging area for a branch (remembers which commit the user started from). */
function stage_new($conn, $repo_id, $branch)
{
    return array('base' => get_ref($conn, $repo_id, $branch), 'sync' => false, 'sync_prefix' => '', 'items' => array());
}

function stage_bytes($stage)
{
    $total = 0;
    foreach ($stage['items'] as $item) {
        if ($item['op'] === 'put') {
            $total += strlen($item['content']);
        }
    }
    return $total;
}

/**
 * Compare staged files with the files of the base commit.
 * Returns array(changes, unchanged_count). Each change: path, status, old, new, content.
 */
function build_changes($base_flat, $stage)
{
    $changes = array();
    $unchanged = 0;
    $items = $stage['items'];

    foreach ($items as $path => $item) {
        $path = (string)$path;
        $old = isset($base_flat[$path]) ? $base_flat[$path] : null;
        if ($item['op'] === 'delete') {
            if ($old !== null) {
                $changes[$path] = array('path' => $path, 'status' => 'deleted', 'old' => $old, 'new' => null, 'content' => null);
            }
        } else {
            $new = hash_blob($item['content']);
            if ($old === null) {
                $changes[$path] = array('path' => $path, 'status' => 'added', 'old' => null, 'new' => $new, 'content' => $item['content']);
            } elseif ($old !== $new) {
                $changes[$path] = array('path' => $path, 'status' => 'modified', 'old' => $old, 'new' => $new, 'content' => $item['content']);
            } else {
                $unchanged++;
            }
        }
    }

    // "Sync" uploads: files that exist in the project but not in the upload are deleted
    if (!empty($stage['sync'])) {
        $prefix = $stage['sync_prefix'];
        foreach ($base_flat as $path => $old) {
            $path = (string)$path;
            if ($prefix !== '' && strpos($path, $prefix) !== 0) {
                continue;
            }
            if (!isset($items[$path]) && !isset($changes[$path])) {
                $changes[$path] = array('path' => $path, 'status' => 'deleted', 'old' => $old, 'new' => null, 'content' => null);
            }
        }
    }
    ksort($changes, SORT_STRING);
    return array($changes, $unchanged);
}

/* ---------- diff entries for the screen ---------- */

/** Build one file entry (with folded line operations) for the diff view. */
function make_file_diff($path, $status, $old_content, $new_content)
{
    $entry = array('path' => $path, 'status' => $status, 'binary' => false, 'big' => false,
        'ops' => array(), 'added' => 0, 'removed' => 0);
    $old = ($old_content === null) ? '' : $old_content;
    $new = ($new_content === null) ? '' : $new_content;

    if (is_binary($old) || is_binary($new)) {
        $entry['binary'] = true;
        return $entry;
    }
    $a = split_lines($old);
    $b = split_lines($new);
    if (count($a) + count($b) > 6000) {
        $entry['big'] = true;
        return $entry;
    }
    $ops = diff_lines($a, $b);
    list($entry['added'], $entry['removed']) = diff_stats($ops);
    $entry['ops'] = fold_context($ops, 3);
    return $entry;
}

/** Differences between two commits (either may be null = empty project). */
function diff_commits($conn, $repo_id, $hash_a, $hash_b)
{
    $ta = null;
    $tb = null;
    if ($hash_a !== null) {
        $c = read_commit($conn, $repo_id, $hash_a);
        $ta = $c ? $c['tree_hash'] : null;
    }
    if ($hash_b !== null) {
        $c = read_commit($conn, $repo_id, $hash_b);
        $tb = $c ? $c['tree_hash'] : null;
    }
    $changes = array();
    diff_trees($conn, $repo_id, $ta, $tb, '', $changes);

    $files = array();
    $added = 0;
    $removed = 0;
    foreach ($changes as $ch) {
        $old = ($ch['old'] !== null) ? read_blob($conn, $repo_id, $ch['old']) : null;
        $new = ($ch['new'] !== null) ? read_blob($conn, $repo_id, $ch['new']) : null;
        $f = make_file_diff($ch['path'], $ch['status'], $old, $new);
        $added += $f['added'];
        $removed += $f['removed'];
        $files[] = $f;
    }
    return array('files' => $files, 'added' => $added, 'removed' => $removed);
}

/* ---------- commit ---------- */

/**
 * Commit the selected staged changes to a branch.
 * Returns the new commit hash. Throws UserError with a readable message.
 */
function do_commit($conn, $repo, $user_id, $branch, $stage, $selected, $message)
{
    $repo_id = (int)$repo['id'];
    $message = trim($message);
    if ($message === '') {
        throw new UserError('Write a commit message.');
    }
    if (text_length($message) > 2000) {
        throw new UserError('The commit message is too long (2000 characters at most).');
    }
    if (count($selected) == 0) {
        throw new UserError('Select at least one file to commit.');
    }

    $conn->begin_transaction();
    try {
        lock_repo($conn, $repo_id);

        // The branch must not have moved since the user started editing
        $head = get_ref($conn, $repo_id, $branch);
        if ($head !== $stage['base']) {
            throw new UserError('This branch changed after you started editing. Discard these changes and try again.');
        }
        if ($head === null && $branch !== $repo['default_branch']) {
            throw new UserError('The first commit must go to the ' . $repo['default_branch'] . ' branch.');
        }

        $base_flat = files_at_commit($conn, $repo_id, $head);
        list($changes, $unchanged) = build_changes($base_flat, $stage);

        $new_flat = $base_flat;
        $count = 0;
        foreach ($changes as $path => $ch) {
            if (!in_array((string)$path, $selected, true)) {
                continue;
            }
            if ($ch['status'] === 'deleted') {
                unset($new_flat[$path]);
            } else {
                $new_flat[$path] = store_blob($conn, $repo_id, $ch['content']);
            }
            $count++;
        }
        if ($count == 0) {
            throw new UserError('None of the selected files has changes.');
        }
        $err = path_conflict($new_flat);
        if ($err !== '') {
            throw new UserError($err);
        }

        $tree = build_tree($conn, $repo_id, $new_flat);
        $parents = ($head === null) ? array() : array($head);
        $commit = store_commit($conn, $repo_id, $tree, $user_id, $message, $parents, time());
        set_ref($conn, $repo_id, $branch, $commit);
        $conn->commit();
        return $commit;
    } catch (UserError $ex) {
        $conn->rollback();
        throw $ex;
    } catch (Exception $ex) {
        $conn->rollback();
        error_log('Commit failed: ' . $ex->getMessage());
        throw new UserError('The commit could not be saved. Please try again.');
    }
}

/* ---------- branches ---------- */

function create_branch($conn, $repo_id, $name, $from_hash)
{
    if (!valid_branch_name($name)) {
        throw new UserError('Branch names use letters, numbers, dots, dashes, underscores and slashes.');
    }
    if (get_ref($conn, $repo_id, $name) !== null) {
        throw new UserError('A branch with that name already exists.');
    }
    if ($from_hash === null) {
        throw new UserError('Make a first commit before creating branches.');
    }
    set_ref($conn, $repo_id, $name, $from_hash);   // a branch is just one row
}

function remove_branch($conn, $repo, $name)
{
    if ($name === $repo['default_branch']) {
        throw new UserError('The default branch cannot be deleted.');
    }
    if (delete_ref($conn, (int)$repo['id'], $name) == 0) {
        throw new UserError('That branch does not exist.');
    }
}

/* ---------- merge ---------- */

/**
 * Work out the result of merging $head_s (source) into $head_t (target).
 * Returns array(files => clean result map, conflicts => path => text with markers, notices => list).
 */
function compute_merge($conn, $repo_id, $head_t, $head_s, $target_name, $source_name)
{
    $parents = parent_map($conn, $repo_id);
    $base_hash = merge_base($parents, $head_t, $head_s);
    $base = files_at_commit($conn, $repo_id, $base_hash);
    $ours = files_at_commit($conn, $repo_id, $head_t);
    $theirs = files_at_commit($conn, $repo_id, $head_s);

    $paths = array_unique(array_merge(array_keys($base), array_keys($ours), array_keys($theirs)));
    sort($paths, SORT_STRING);

    $result = array();
    $conflicts = array();
    $notices = array();

    foreach ($paths as $path) {
        $path = (string)$path;
        $b = isset($base[$path]) ? $base[$path] : null;
        $o = isset($ours[$path]) ? $ours[$path] : null;
        $t = isset($theirs[$path]) ? $theirs[$path] : null;

        if ($o === $t) {
            $r = $o;                       // same on both sides
        } elseif ($o === $b) {
            $r = $t;                       // only the other branch changed it
        } elseif ($t === $b) {
            $r = $o;                       // only this branch changed it
        } elseif ($o === null || $t === null) {
            // deleted on one side, changed on the other: keep the changed file
            $r = ($o === null) ? $t : $o;
            $notices[] = $path . ' was deleted on one branch and changed on the other. The changed file was kept.';
        } else {
            $bc = ($b !== null) ? read_blob($conn, $repo_id, $b) : '';
            $oc = read_blob($conn, $repo_id, $o);
            $tc = read_blob($conn, $repo_id, $t);
            if (is_binary($bc) || is_binary($oc) || is_binary($tc)) {
                $r = $o;
                $notices[] = $path . ' is a binary file changed on both branches. The version from ' . $target_name . ' was kept.';
            } else {
                $m = merge_text($bc, $oc, $tc, $target_name, $source_name);
                if ($m['conflicts'] == 0) {
                    $r = store_blob($conn, $repo_id, $m['text']);
                } else {
                    $conflicts[$path] = $m['text'];
                    continue;
                }
            }
        }
        if ($r !== null) {
            $result[$path] = $r;
        }
    }
    return array('files' => $result, 'conflicts' => $conflicts, 'notices' => $notices);
}

/**
 * Merge branch $source into branch $target.
 * $resolved is null on the first try, or path => resolved text after the user fixed conflicts.
 * Returns array('status' => up_to_date | fast_forward | merged | conflict, ...).
 */
function merge_branches($conn, $repo, $user_id, $source, $target, $resolved, $expect_t, $expect_s)
{
    $repo_id = (int)$repo['id'];
    if ($source === $target) {
        throw new UserError('Choose two different branches.');
    }

    $conn->begin_transaction();
    try {
        lock_repo($conn, $repo_id);
        $head_t = get_ref($conn, $repo_id, $target);
        $head_s = get_ref($conn, $repo_id, $source);
        if ($head_t === null || $head_s === null) {
            throw new UserError('One of the branches does not exist.');
        }
        if ($expect_t !== null && ($expect_t !== $head_t || $expect_s !== $head_s)) {
            throw new UserError('A branch changed while you were resolving. Start the merge again.');
        }

        $parents = parent_map($conn, $repo_id);
        $anc_t = ancestors($parents, $head_t);
        if (isset($anc_t[$head_s])) {
            $conn->rollback();
            return array('status' => 'up_to_date');
        }
        $anc_s = ancestors($parents, $head_s);
        if (isset($anc_s[$head_t])) {
            // Fast forward: the target has nothing new, so just move its pointer
            set_ref($conn, $repo_id, $target, $head_s);
            $conn->commit();
            return array('status' => 'fast_forward', 'hash' => $head_s);
        }

        $m = compute_merge($conn, $repo_id, $head_t, $head_s, $target, $source);
        $files = $m['files'];

        if (count($m['conflicts']) > 0) {
            if ($resolved === null) {
                $conn->rollback();
                return array('status' => 'conflict', 'conflicts' => $m['conflicts'], 'notices' => $m['notices'],
                    'head_t' => $head_t, 'head_s' => $head_s);
            }
            foreach ($m['conflicts'] as $path => $text) {
                if (!isset($resolved[$path])) {
                    throw new UserError('Resolve every conflicted file before committing.');
                }
                $final = str_replace("\r\n", "\n", $resolved[$path]);
                if (has_conflict_markers($final)) {
                    throw new UserError('The file ' . $path . ' still contains conflict markers.');
                }
                $files[$path] = store_blob($conn, $repo_id, $final);
            }
        }

        $err = path_conflict($files);
        if ($err !== '') {
            throw new UserError($err);
        }
        $tree = build_tree($conn, $repo_id, $files);
        $message = "Merge branch '" . $source . "' into " . $target;
        $commit = store_commit($conn, $repo_id, $tree, $user_id, $message, array($head_t, $head_s), time());
        set_ref($conn, $repo_id, $target, $commit);
        $conn->commit();
        return array('status' => 'merged', 'hash' => $commit, 'notices' => $m['notices']);
    } catch (UserError $ex) {
        $conn->rollback();
        throw $ex;
    } catch (Exception $ex) {
        $conn->rollback();
        error_log('Merge failed: ' . $ex->getMessage());
        throw new UserError('The merge could not be saved. Please try again.');
    }
}
