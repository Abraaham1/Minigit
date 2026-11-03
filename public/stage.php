<?php
// Receives edits and uploads and keeps them in the staging area (session).
// Nothing is committed here: the user reviews the changes first (changes.php).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/engine.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('home.php');
}

define('MAX_FILE_BYTES', 2 * 1024 * 1024);

/** Check the branch the user wants to change. Returns the branch name to use. */
function writable_branch($conn, $repo, $branch)
{
    $refs = list_refs($conn, (int)$repo['id']);
    if (count($refs) == 0) {
        return $repo['default_branch'];        // first commit creates the default branch
    }
    if (!isset($refs[$branch])) {
        throw new UserError('Choose a branch to change.');
    }
    return $branch;
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

/* ---------------- JSON upload (files read in the browser) ---------------- */
if (stripos($type, 'application/json') === 0) {
    $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        json_out(array('ok' => false, 'error' => 'Your session expired. Reload the page and try again.'), 403);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
        json_out(array('ok' => false, 'error' => 'The upload was not understood.'), 400);
    }
    $repo = user_repo($conn, isset($data['repo']) ? (int)$data['repo'] : 0, $_SESSION['user_id']);
    if (!$repo) {
        json_out(array('ok' => false, 'error' => 'Repository not found.'), 404);
    }
    $id = (int)$repo['id'];
    try {
        $branch = writable_branch($conn, $repo, isset($data['branch']) ? (string)$data['branch'] : '');
        $prefix = clean_path(isset($data['prefix']) ? (string)$data['prefix'] : '');
        if ($prefix === false) {
            throw new UserError('The folder name is not valid.');
        }
        if (count($data['files']) == 0 || count($data['files']) > 2000) {
            throw new UserError('Choose between 1 and 2000 files.');
        }

        $stage = stage_get($id, $branch);
        if ($stage === null) {
            $stage = stage_new($conn, $id, $branch);
        }
        foreach ($data['files'] as $f) {
            if (!is_array($f) || !isset($f['path']) || !isset($f['b64'])) {
                continue;
            }
            $p = clean_path(($prefix === '' ? '' : $prefix . '/') . $f['path']);
            if ($p === false || $p === '') {
                throw new UserError('A file name is not valid: ' . substr((string)$f['path'], 0, 100));
            }
            $content = base64_decode((string)$f['b64'], true);
            if ($content === false) {
                throw new UserError('A file could not be read: ' . $p);
            }
            if (strlen($content) > MAX_FILE_BYTES) {
                throw new UserError($p . ' is larger than 2 MB.');
            }
            $stage['items'][$p] = array('op' => 'put', 'content' => $content);
        }
        if (!empty($data['sync'])) {
            $stage['sync'] = true;
            $stage['sync_prefix'] = ($prefix === '') ? '' : $prefix . '/';
        }
        if (stage_bytes($stage) > STAGE_MAX_BYTES) {
            throw new UserError('The upload is larger than 6 MB in total. Upload fewer files at a time.');
        }
        stage_save($id, $branch, $stage);
        json_out(array('ok' => true));
    } catch (UserError $ex) {
        json_out(array('ok' => false, 'error' => $ex->getMessage()), 400);
    }
}

/* ---------------- normal form posts: put, delete, discard ---------------- */
if (!csrf_valid()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('home.php');
}
$repo = require_repo($conn);
$id = (int)$repo['id'];
$action = post_str('action');

try {
    $branch = writable_branch($conn, $repo, post_str('branch'));
    $stage = stage_get($id, $branch);
    if ($stage === null) {
        $stage = stage_new($conn, $id, $branch);
    }

    if ($action === 'put') {
        $path = clean_path(post_str('path'));
        if ($path === false || $path === '') {
            throw new UserError('That file name is not valid.');
        }
        $content = str_replace("\r\n", "\n", post_str('content', false));
        if (strlen($content) > MAX_FILE_BYTES) {
            throw new UserError('Files can be up to 2 MB.');
        }
        $stage['items'][$path] = array('op' => 'put', 'content' => $content);
        if (stage_bytes($stage) > STAGE_MAX_BYTES) {
            throw new UserError('Too much content is waiting for review. Commit or discard first.');
        }
        stage_save($id, $branch, $stage);
        redirect(url_to('changes.php', array('repo' => $id, 'branch' => $branch)));

    } elseif ($action === 'delete') {
        $path = clean_path(post_str('path'));
        if ($path === false || $path === '') {
            throw new UserError('That file name is not valid.');
        }
        $stage['items'][$path] = array('op' => 'delete');
        stage_save($id, $branch, $stage);
        redirect(url_to('changes.php', array('repo' => $id, 'branch' => $branch)));

    } elseif ($action === 'discard') {
        stage_clear($id, $branch);
        flash_set('ok', 'The waiting changes were discarded.');
        redirect(url_to('repo.php', array('repo' => $id, 'ref' => $branch)));

    } else {
        throw new UserError('Unknown action.');
    }
} catch (UserError $ex) {
    flash_set('error', $ex->getMessage());
    redirect(url_to('repo.php', array('repo' => $id)));
}
