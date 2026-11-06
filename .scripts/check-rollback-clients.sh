#!/usr/bin/env bash
# Since 1.15 (audit HA-H2): runs docs/sql/1.15.0 through the native clients with their DEFAULT options. A rollback must
# refuse and change nothing while an entry has no user, and must work once none is left. Containers are removed on exit.
# Usage: .scripts/check-rollback-clients.sh [sqlite] [mysql] [mariadb] [postgres]   (default: all four)
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SQL="$ROOT/docs/sql/1.15.0"
TARGETS="${*:-sqlite mysql mariadb postgres}"
PREFIX="tt-rollback-$$"
FAILED=0
trap 'docker rm -f "$PREFIX-mysql" "$PREFIX-mariadb" "$PREFIX-postgres" "$PREFIX-sqlite" >/dev/null 2>&1' EXIT

fail() { echo "FAIL [$1]: $2"; FAILED=1; }
pass() { echo "ok   [$1]: $2"; }
same() { if [ "$3" = "$4" ]; then pass "$1" "$2 = $3"; else fail "$1" "$2: expected '$4', got '$3'"; fi; }

# Rows of the fixture: 3 entries of user 1, 1 of user 2. `ownerless` adds a former person's.
FIXTURE_ROWS="INSERT INTO users (id) VALUES (1), (2);
INSERT INTO time_track_entries (id, created_at, external_source, external_id, billable, description, scope_key, task_id, task_title_snapshot, board_id_snapshot, started_at, ended_at, duration_seconds, source, metadata, user_id) VALUES
('a', '2026-01-01 10:00:00', NULL, NULL, NULL, 'd', NULL, 't', 'T', NULL, '2026-01-01 10:00:00', '2026-01-01 11:00:00', 3600, 'web', NULL, 1),
('b', '2026-01-01 10:00:00', NULL, NULL, NULL, NULL, NULL, 't', 'T', NULL, '2026-01-01 10:00:00', '2026-01-01 11:00:00', 3600, 'web', NULL, 1),
('c', '2026-01-01 10:00:00', NULL, NULL, NULL, NULL, NULL, 't', 'T', NULL, '2026-01-01 10:00:00', '2026-01-01 11:00:00', 3600, 'web', NULL, 1),
('d', '2026-01-01 10:00:00', NULL, NULL, NULL, NULL, NULL, 't', 'T', NULL, '2026-01-01 10:00:00', '2026-01-01 11:00:00', 1800, 'web', NULL, 2);"
OWNERLESS="INSERT INTO time_track_entries (id, created_at, task_id, task_title_snapshot, started_at, ended_at, duration_seconds, source, person_name_snapshot, person_ref) VALUES ('p', '2026-01-01 10:00:00', 't', 'T', '2026-01-01 10:00:00', '2026-01-01 11:00:00', 600, 'import', 'Ana', 'p-1');"

mysql_like_schema() {
  cat <<SQL
CREATE TABLE users (id INT NOT NULL, PRIMARY KEY (id));
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, owner_ref VARCHAR(64) NOT NULL, period_end DATE NOT NULL, PRIMARY KEY (id));
CREATE TABLE time_track_entries (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable TINYINT(1) DEFAULT NULL, description LONGTEXT DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, duration_seconds INT NOT NULL, source VARCHAR(16) NOT NULL, metadata JSON DEFAULT NULL, user_id INT NOT NULL, INDEX time_track_entries_user_idx (user_id), PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE);
SQL
}
postgres_schema() {
  cat <<SQL
CREATE TABLE users (id INT NOT NULL, PRIMARY KEY (id));
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, owner_ref VARCHAR(64) NOT NULL, period_end DATE NOT NULL, PRIMARY KEY (id));
CREATE TABLE time_track_entries (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable BOOLEAN DEFAULT NULL, description TEXT DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at TIMESTAMP(0) NOT NULL, ended_at TIMESTAMP(0) NOT NULL, duration_seconds INT NOT NULL, source VARCHAR(16) NOT NULL, metadata JSON DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE);
CREATE INDEX time_track_entries_user_idx ON time_track_entries (user_id);
SQL
}
sqlite_schema() {
  cat <<SQL
CREATE TABLE users (id INTEGER NOT NULL, PRIMARY KEY (id));
CREATE TABLE time_track_timesheets (id VARCHAR(36) NOT NULL, owner_ref VARCHAR(64) NOT NULL, period_end DATE NOT NULL, PRIMARY KEY (id));
CREATE TABLE time_track_entries (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, external_source VARCHAR(64) DEFAULT NULL, external_id VARCHAR(128) DEFAULT NULL, billable BOOLEAN DEFAULT NULL, description CLOB DEFAULT NULL, scope_key VARCHAR(64) DEFAULT NULL, task_id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) NOT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, duration_seconds INTEGER NOT NULL, source VARCHAR(16) NOT NULL, metadata CLOB DEFAULT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7CB77957A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE);
CREATE INDEX time_track_entries_user_idx ON time_track_entries (user_id);
CREATE INDEX time_track_entries_task_idx ON time_track_entries (task_id);
CREATE INDEX time_track_entries_started_idx ON time_track_entries (started_at);
CREATE INDEX time_track_entries_ended_idx ON time_track_entries (ended_at, id);
CREATE INDEX idx_time_track_entries_source ON time_track_entries (source);
CREATE UNIQUE INDEX uniq_time_track_entries_external ON time_track_entries (external_source, external_id);
CREATE INDEX idx_time_track_entries_user_scope_started ON time_track_entries (user_id, scope_key, started_at);
SQL
}

# The scenario, for a client given by run_sql (feeds a file or stdin) and query (prints one value).
scenario() {
  local name="$1" dir="$2"
  run_sql "$dir" "$SQL/$dir-up.sql" >/dev/null 2>&1 || fail "$name" "up exited $?"
  same "$name" "rows after up" "$(query 'SELECT COUNT(*) FROM time_track_entries')" 4
  same "$name" "seconds after up" "$(query 'SELECT SUM(duration_seconds) FROM time_track_entries')" 12600
  echo "$OWNERLESS" | run_stdin >/dev/null 2>&1 || fail "$name" "could not insert the ownerless row"

  local before; before="$(query "SELECT COUNT(*) FROM time_track_entries WHERE person_ref = 'p-1' AND person_name_snapshot = 'Ana'")"
  same "$name" "ownerless row before rollback" "$before" 1

  run_sql "$dir" "$SQL/$dir-down.sql" >/tmp/$PREFIX.out 2>&1
  local code=$?
  if [ "$code" = 0 ]; then fail "$name" "the refused rollback exited 0"; else pass "$name" "refused rollback exited $code"; fi
  same "$name" "rows after refused rollback" "$(query 'SELECT COUNT(*) FROM time_track_entries')" 5
  same "$name" "seconds after refused rollback" "$(query 'SELECT SUM(duration_seconds) FROM time_track_entries')" 13200
  same "$name" "name and reference kept" "$(query "SELECT COUNT(*) FROM time_track_entries WHERE person_ref = 'p-1' AND person_name_snapshot = 'Ana'")" 1
  same "$name" "anonymised_at still there" "$(query 'SELECT COUNT(anonymised_at) FROM time_track_entries')" 0
  same "$name" "owner_ref index still there" "$(has_index idx_time_track_timesheets_owner_ref)" 1
  same "$name" "person_ref index still there" "$(has_index idx_time_track_entries_person_ref)" 1

  # The foreign key is still SET NULL: a user's delete keeps the rows.
  echo "DELETE FROM users WHERE id = 2;" | run_stdin >/dev/null 2>&1
  same "$name" "user delete keeps the rows (SET NULL)" "$(query 'SELECT COUNT(*) FROM time_track_entries')" 5
  same "$name" "... and clears the user" "$(query 'SELECT COUNT(*) FROM time_track_entries WHERE user_id IS NULL')" 2

  # A second refusal changes nothing either.
  run_sql "$dir" "$SQL/$dir-down.sql" >/dev/null 2>&1
  same "$name" "rows after a second refusal" "$(query 'SELECT COUNT(*) FROM time_track_entries')" 5

  # Once no entry is ownerless the rollback works and the up script runs again.
  echo "INSERT INTO users (id) VALUES (2); UPDATE time_track_entries SET user_id = 2 WHERE user_id IS NULL;" | run_stdin >/dev/null 2>&1
  run_sql "$dir" "$SQL/$dir-down.sql" >/tmp/$PREFIX.out 2>&1 || { fail "$name" "the clean rollback exited $?"; cat /tmp/$PREFIX.out; }
  same "$name" "rows after a clean rollback" "$(query 'SELECT COUNT(*) FROM time_track_entries')" 5
  same "$name" "person_ref gone" "$(has_column person_ref)" 0
  same "$name" "owner_ref index gone" "$(has_index idx_time_track_timesheets_owner_ref)" 0
  echo "DELETE FROM users WHERE id = 2;" | run_stdin >/dev/null 2>&1
  same "$name" "CASCADE is back after a clean rollback" "$(query 'SELECT COUNT(*) FROM time_track_entries WHERE user_id = 2')" 0
  run_sql "$dir" "$SQL/$dir-up.sql" >/dev/null 2>&1 || fail "$name" "up after down exited $?"
  same "$name" "person_ref back" "$(has_column person_ref)" 1
}

wait_for() { # container, probe...
  local c="$1"; shift
  for _ in $(seq 1 90); do docker exec "$c" "$@" >/dev/null 2>&1 && return 0; sleep 2; done
  echo "FAIL: $c did not start"; docker logs "$c" 2>&1 | tail -5; FAILED=1; return 1
}

mysql_family() {
  local name="$1" image="$2" client="$3" c="$PREFIX-$1"
  docker run -d --name "$c" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=tt --tmpfs /var/lib/mysql "$image" >/dev/null || { FAILED=1; return; }
  wait_for "$c" "$client" -uroot -proot tt -e 'SELECT 1' || return
  run_stdin() { docker exec -i "$c" "$client" -uroot -proot tt; }
  run_sql() { docker exec -i "$c" "$client" -uroot -proot tt < "$2"; }
  query() { docker exec -i "$c" "$client" -uroot -proot tt -N -B -e "$1" 2>/dev/null; }
  has_index() { query "SELECT COUNT(*) > 0 FROM information_schema.statistics WHERE table_schema = 'tt' AND index_name = '$1'"; }
  has_column() { query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'tt' AND table_name = 'time_track_entries' AND column_name = '$1'"; }
  { mysql_like_schema; echo "$FIXTURE_ROWS"; } | run_stdin || fail "$name" "fixture"
  scenario "$name" mysql
  # Non-strict sql_mode must not turn a null user into 0.
  docker exec -i "$c" "$client" -uroot -proot -e "SET GLOBAL sql_mode = ''"
  echo "UPDATE time_track_entries SET user_id = NULL WHERE id = 'a';" | run_stdin
  run_sql x "$SQL/mysql-down.sql" >/dev/null 2>&1
  same "$name" "non-strict server: null user stays null" "$(query "SELECT COUNT(*) FROM time_track_entries WHERE id = 'a' AND user_id IS NULL")" 1
  same "$name" "non-strict server: person_ref kept" "$(has_column person_ref)" 1
  docker rm -f "$c" >/dev/null
}

postgres() {
  local c="$PREFIX-postgres"
  docker run -d --name "$c" -e POSTGRES_PASSWORD=pg -e POSTGRES_DB=tt --tmpfs /var/lib/postgresql/data postgres:16-alpine >/dev/null || { FAILED=1; return; }
  wait_for "$c" psql -U postgres -d tt -c 'SELECT 1' || return
  run_stdin() { docker exec -i "$c" psql -U postgres -d tt -q; }
  run_sql() { docker cp "$2" "$c:/tmp/run.sql" >/dev/null && docker exec "$c" psql -U postgres -d tt -q -f /tmp/run.sql; }
  query() { docker exec -i "$c" psql -U postgres -d tt -At -c "$1" 2>/dev/null; }
  has_index() { query "SELECT COUNT(*) FROM pg_indexes WHERE indexname = '$1'"; }
  has_column() { query "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'time_track_entries' AND column_name = '$1'"; }
  { postgres_schema; echo "$FIXTURE_ROWS"; } | run_stdin || fail postgres "fixture"
  scenario postgres postgresql
  docker rm -f "$c" >/dev/null
}

sqlite_cli() {
  local c="$PREFIX-sqlite"
  docker run -d --name "$c" alpine:3.20 sleep 600 >/dev/null || { FAILED=1; return; }
  docker exec "$c" apk add -q sqlite >/dev/null || { fail sqlite "apk add sqlite"; return; }
  # A foreign-key enforcing session, as a host's would be; the scripts turn it off themselves for the rebuild.
  run_stdin() { { echo 'PRAGMA foreign_keys = ON;'; cat; } | docker exec -i "$c" sqlite3 /tmp/t.db; }
  run_sql() { docker exec -i "$c" sqlite3 /tmp/t.db < "$2"; }
  query() { docker exec "$c" sqlite3 /tmp/t.db "$1" 2>/dev/null; }
  has_index() { query "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = '$1'"; }
  has_column() { query "SELECT COUNT(*) FROM pragma_table_info('time_track_entries') WHERE name = '$1'"; }
  { sqlite_schema; echo "$FIXTURE_ROWS"; } | run_stdin || fail sqlite "fixture"
  scenario sqlite sqlite
  same sqlite "no temporary table left" "$(query "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('time_track_entries_new', 'time_track_entries_old')")" 0
  docker rm -f "$c" >/dev/null
}

for target in $TARGETS; do
  echo "=== $target"
  case "$target" in
    sqlite)   sqlite_cli ;;
    mysql)    mysql_family mysql mysql:8.0 mysql ;;
    mariadb)  mysql_family mariadb mariadb:10.6 mariadb ;;
    postgres) postgres ;;
    *) echo "unknown target: $target"; FAILED=1 ;;
  esac
done

if [ "$FAILED" != 0 ]; then echo "FAILED"; exit 1; fi
echo "All rollback checks passed."
