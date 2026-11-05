# MiniGit

A GitHub style website for storing code, committing changes, reviewing diffs, branching and merging.
Written in PHP, HTML, CSS, JavaScript and MySQL. The version control engine is written from scratch
(no git commands): files, folders and commits are stored in MySQL and named by the SHA-1 hash of their content.

## Setup (Ubuntu)
1. Copy everything into /var/www/dev  (Apache DocumentRoot = /var/www/dev/public)
2. Create the database:  sudo mysql < db/schema.sql
3. Put the real database password in config/database.php (the same one MySQL has for minigit_user)
4. Open http://dev.local

## Folders
public/    pages the browser can open
config/    database connection (outside public/)
includes/  the engine and shared code
  diff.php        line diff and three way merge (no database)
  objects.php     blobs, trees, commits, branches (the object store)
  repo_ops.php    repositories, staging, commit, branches, merge
  repo_ui.php     header, tabs, helpers for the pages
  diff_view.php   draws diffs as HTML
db/        schema.sql for the whole project
tests/     php tests/test_diff.php   (no database needed)
           php tests/test_engine.php (uses the database, cleans up after itself)

## Pages
home.php          your repositories
repo_new.php      create a repository
repo.php          browse files at a branch or commit
file.php          view or edit one file (raw.php downloads it)
new_file.php      write a new file in the browser
upload.php        upload files or a whole folder
stage.php         keeps edits and uploads waiting for review (no commit yet)
changes.php       Review changes: added/modified/deleted files, line diffs, tick files, commit
commits.php       history of a branch
commit.php        one commit with its diff
compare.php       diff between any two branches or commits
branches.php      create, delete and merge branches
merge.php         merge and conflict resolution
graph.php         commit graph (SVG)
settings.php      repository details and delete

## Notes
- Syntax highlighting loads highlight.js from cdnjs, so it needs internet. Without it, code is shown as plain text.
- Uploads: up to 2 MB per file and 4 MB per upload in the browser. PHP's default post_max_size (8M) is enough.
- Waiting changes are kept in the PHP session until you commit or discard them.
