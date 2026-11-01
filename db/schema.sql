CREATE DATABASE IF NOT EXISTS minigit
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'minigit_user'@'localhost' IDENTIFIED BY 'Pakistan1947!';
GRANT ALL PRIVILEGES ON minigit.* TO 'minigit_user'@'localhost';
FLUSH PRIVILEGES;

USE minigit;

-- users

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100)  NOT NULL,
  email         VARCHAR(255)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,          -- password_hash(), never the password
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

--repos

CREATE TABLE IF NOT EXISTS repos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id       INT UNSIGNED  NOT NULL,
  name           VARCHAR(100)  NOT NULL,
  description    VARCHAR(255)  NULL,
  default_branch VARCHAR(100)  NOT NULL DEFAULT 'main',
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_owner_repo (owner_id, name),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

--blobs

CREATE TABLE IF NOT EXISTS blobs (
  repo_id INT UNSIGNED NOT NULL,
  hash    CHAR(40)     NOT NULL,
  content LONGBLOB     NOT NULL,
  size    INT UNSIGNED NOT NULL,
  PRIMARY KEY (repo_id, hash),
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

--trees

CREATE TABLE IF NOT EXISTS trees (
  repo_id INT UNSIGNED NOT NULL,
  hash    CHAR(40)     NOT NULL,
  PRIMARY KEY (repo_id, hash),
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tree_entries (
  repo_id     INT UNSIGNED NOT NULL,
  tree_hash   CHAR(40)     NOT NULL,
  name        VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,  -- case-sensitive file names
  mode        CHAR(6)      NOT NULL DEFAULT '100644',
  type        ENUM('blob','tree') NOT NULL,
  object_hash CHAR(40)     NOT NULL,          -- points to a blob or a tree (decided by `type`)
  PRIMARY KEY (repo_id, tree_hash, name),
  FOREIGN KEY (repo_id, tree_hash) REFERENCES trees(repo_id, hash) ON DELETE CASCADE
) ENGINE=InnoDB;

--commits

CREATE TABLE IF NOT EXISTS commits (
  repo_id    INT UNSIGNED NOT NULL,
  hash       CHAR(40)     NOT NULL,
  tree_hash  CHAR(40)     NOT NULL,
  author_id  INT UNSIGNED NOT NULL,
  message    TEXT         NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (repo_id, hash),
  KEY idx_commits_author (author_id),
  FOREIGN KEY (repo_id)            REFERENCES repos(id)            ON DELETE CASCADE,
  FOREIGN KEY (repo_id, tree_hash) REFERENCES trees(repo_id, hash) ON DELETE CASCADE,
  FOREIGN KEY (author_id)          REFERENCES users(id)
) ENGINE=InnoDB;

-- commit_parents: 0 parents = first commit, 1 = normal, 2 = merge commit
CREATE TABLE IF NOT EXISTS commit_parents (
  repo_id     INT UNSIGNED NOT NULL,
  commit_hash CHAR(40)     NOT NULL,
  parent_hash CHAR(40)     NOT NULL,
  ord         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (repo_id, commit_hash, parent_hash),
  KEY idx_parent (repo_id, parent_hash),
  FOREIGN KEY (repo_id, commit_hash) REFERENCES commits(repo_id, hash) ON DELETE CASCADE,
  FOREIGN KEY (repo_id, parent_hash) REFERENCES commits(repo_id, hash) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS refs (
  repo_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  commit_hash CHAR(40)     NOT NULL,
  PRIMARY KEY (repo_id, name),
  FOREIGN KEY (repo_id)              REFERENCES repos(id)              ON DELETE CASCADE,
  FOREIGN KEY (repo_id, commit_hash) REFERENCES commits(repo_id, hash) ON DELETE CASCADE
) ENGINE=InnoDB;
