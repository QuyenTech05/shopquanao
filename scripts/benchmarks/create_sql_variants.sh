#!/usr/bin/env bash
set -euo pipefail
umask 077
repo="$(cd "$(dirname "$0")/../.." && pwd)"
schema= workdir=
while (($#)); do
    case "$1" in
        --source-schema) schema="${2:?}"; shift 2 ;;
        --workdir) workdir="${2:?}"; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
if [[ -z "$workdir" || ! -d "$workdir" || -L "$workdir" ]]; then
    echo 'An empty temporary directory is required' >&2
    exit 2
fi
workdir="$(realpath "$workdir")"
temp_root="$(realpath "${TMPDIR:-/tmp}")"
if [[ "$workdir" != "$temp_root"/* || "$workdir" == "$temp_root" || ! -O "$workdir" || -n "$(find "$workdir" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    echo 'An empty temporary directory owned by the caller under /tmp is required' >&2
    exit 2
fi
[[ -f "$schema" ]] || { echo 'Schema file missing' >&2; exit 2; }
schema="$(realpath "$schema")"
prefix="sqlbench_$(python3 -c 'import secrets; print(secrets.token_hex(6))')"
indexed="${prefix}_indexed"
baseline="${prefix}_baseline"
root_password="$(python3 -c 'import secrets; print(secrets.token_hex(24))')"
reader_password="$(python3 -c 'import secrets; print(secrets.token_hex(24))')"
export MARIADB_ROOT_PASSWORD="$root_password"
cid="$(docker run -d --network none --name "$prefix" --label purpose=disposable-sql-benchmark -e MARIADB_ROOT_PASSWORD -e MARIADB_INITDB_SKIP_TZINFO=1 mariadb:10.11 --innodb-buffer-pool-size=256M --innodb-flush-log-at-trx-commit=2 --query-cache-type=0 --query-cache-size=0)"
trap 'status=$?; if ((status != 0)); then docker logs --tail 35 "$cid" >&2 || true; fi; docker rm -fv "$cid" >/dev/null 2>&1 || true' EXIT
unset MARIADB_ROOT_PASSWORD
sql() {
    docker exec -i "$cid" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -h127.0.0.1 -uroot --batch --skip-column-names "$@"' sh "$@"
}
for attempt in $(seq 1 180); do
    if sql -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
sql -e "CREATE DATABASE $indexed CHARACTER SET utf8mb4; CREATE DATABASE $baseline CHARACTER SET utf8mb4;"
# Strip tracked fixture INSERT statements and database selection. Import DDL only.
awk '/^CREATE DATABASE|^USE / {next} /^INSERT INTO / {skip=1} skip {if (/;[[:space:]]*$/) skip=0; next} {print}' "$schema" | sql "$indexed"
sql "$indexed" -e "INSERT INTO categories (id,name) VALUES (1,'Synthetic tops'),(2,'Synthetic bottoms'),(3,'Synthetic dresses'),(4,'Synthetic accessories');"
for migration in "$repo"/database/migrations/*.sql; do
    if [[ "$(basename "$migration")" == 2026_08_24_fashion_provider_mapping_identifiers.sql ]]; then
        # This ALTER is paired with the preceding CREATE migration; apply it only
        # when the benchmark schema contains the provider-mapping table.
        if ! sql "$indexed" -e 'SHOW TABLES LIKE "fashion_provider_product_mapping"' | grep -q .; then
            continue
        fi
    fi
    if [[ "$(basename "$migration")" == 2026_06_20_ensure_users_table.sql ]]; then
        # Exclude the application bootstrap admin account.
        awk '/^INSERT INTO `users`/ {skip=1} skip {if (/;[[:space:]]*$/) skip=0; next} {print}' "$migration" | sql "$indexed"
    else
        sql "$indexed" < "$migration"
    fi
done
sql "$indexed" -e "CREATE TABLE benchmark_fixture_marker (marker VARCHAR(80) PRIMARY KEY); INSERT INTO benchmark_fixture_marker VALUES ('disposable-synthetic-sql-benchmark-v1');"
export BENCHMARK_DSN="mysql:host=127.0.0.1;port=3306;dbname=$indexed;charset=utf8mb4"
export BENCHMARK_USER=root BENCHMARK_PASSWORD="$root_password"
docker run --rm --network "container:$cid" -u "$(id -u):$(id -g)" -v "$repo:/w:ro" -v "$workdir:/benchmark" -w /w -e BENCHMARK_DSN -e BENCHMARK_USER -e BENCHMARK_PASSWORD --entrypoint php "${DEV_PHP_IMAGE:-shop_quan_ao-app:latest}" scripts/benchmarks/seed_sql_benchmark.php --scale=large --seed=20261127 --output=/benchmark
unset BENCHMARK_DSN BENCHMARK_USER BENCHMARK_PASSWORD
# Clone a single materialized synthetic fixture so timestamps and all rows match.
docker exec "$cid" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --order-by-primary --skip-comments --skip-dump-date "$1"' sh "$indexed" | sql "$baseline"
sql "$baseline" < "$repo/scripts/benchmarks/index-manifest.sql"
python3 - "$cid" "$indexed" "$baseline" "$workdir" "$repo/scripts/benchmarks/index-manifest.sql" <<'PY'
import hashlib, json, pathlib, re, subprocess, sys
cid, indexed, baseline, workdir, manifest_path = sys.argv[1:]
root = pathlib.Path(workdir)
def query(db, statement):
    result = subprocess.check_output([
        "docker", "exec", cid, "sh", "-c",
        'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot -BN "$@"', "sh", db, "-e", statement
    ], text=True)
    return [line.split("\t") for line in result.splitlines()]
def snapshot(db):
    tables = [r[0] for r in query(db, "SHOW TABLES")]
    counts = {}
    checksums = {}
    schema = {}
    for table in tables:
        if not re.fullmatch(r"[a-zA-Z0-9_]+", table):
            raise RuntimeError("Unexpected table identifier")
        query(db, f"ANALYZE TABLE `{table}`")
        counts[table] = int(query(db, f"SELECT COUNT(*) FROM `{table}`")[0][0])
        checksum = query(db, f"CHECKSUM TABLE `{table}` EXTENDED")[0][1]
        if checksum == "NULL":
            raise RuntimeError(f"Checksum unsupported: {table}")
        checksums[table] = checksum
        schema[table] = query(db, f"SHOW CREATE TABLE `{table}`")[0][1]
    indexes = query(db, "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COALESCE(SUB_PART,0),INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY 1,2,4")
    constraints = query(db, "SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() ORDER BY 1,2")
    keys = query(db, "SELECT TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION,COLUMN_NAME,COALESCE(REFERENCED_TABLE_NAME,''),COALESCE(REFERENCED_COLUMN_NAME,'') FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() ORDER BY 1,2,3")
    rules = query(db, "SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY 1,2")
    columns = query(db, "SELECT TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,''),EXTRA,COALESCE(COLLATION_NAME,'') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY 1,2")
    return dict(row_counts=counts, checksums=checksums, indexes=indexes, constraints=constraints,
                constraint_columns=keys, foreign_key_rules=rules, columns=columns,
                schema_sha256=hashlib.sha256(json.dumps(schema,sort_keys=True).encode()).hexdigest())
a,b = snapshot(indexed),snapshot(baseline)
for key in ("row_counts","checksums","constraints","constraint_columns","foreign_key_rules","columns"):
    if a[key] != b[key]:
        raise RuntimeError(f"Variant mismatch: {key}")
declared = set(re.findall(r"ALTER TABLE (\w+) DROP INDEX (\w+);",pathlib.Path(manifest_path).read_text()))
removed = [r for r in a["indexes"] if r not in b["indexes"]]
added = [r for r in b["indexes"] if r not in a["indexes"]]
if added or {(r[0],r[1]) for r in removed} != declared or any(r[2] != "1" for r in removed):
    raise RuntimeError("Unexpected index delta")
seed = json.loads((root/"seed-manifest.json").read_text())
for table, count in seed["counts"].items():
    if a["row_counts"].get(table) != count:
        raise RuntimeError(f"Fixture row count mismatch: {table}")
for name,data in (("indexed",a),("baseline",b)):
    (root/f"{name}-manifest.json").write_text(json.dumps(data,indent=2,ensure_ascii=False)+"\n")
(root/"variant-verification.json").write_text(json.dumps({"verified":True,"removed_indexes":sorted(declared),"row_counts":a["row_counts"],"checksums":a["checksums"]},indent=2)+"\n")
print(f"Verified {len(a['row_counts'])} tables, {sum(a['row_counts'].values())} rows, {len(declared)} removed secondary indexes")
PY
sql -e "CREATE USER 'benchmark_reader'@'%' IDENTIFIED BY '$reader_password'; GRANT SELECT ON $indexed.* TO 'benchmark_reader'@'%'; GRANT SELECT ON $baseline.* TO 'benchmark_reader'@'%';"
printf 'BENCHMARK_CONTAINER=%q\nINDEXED_DSN=%q\nBASELINE_DSN=%q\nBENCHMARK_USER=%q\nBENCHMARK_PASSWORD=%q\n' "$cid" "mysql:host=127.0.0.1;port=3306;dbname=$indexed;charset=utf8mb4" "mysql:host=127.0.0.1;port=3306;dbname=$baseline;charset=utf8mb4" "benchmark_reader" "$reader_password" > "$workdir/runtime.env"
trap - EXIT
echo "Verified isolated variants. Runtime configuration: $workdir/runtime.env"
