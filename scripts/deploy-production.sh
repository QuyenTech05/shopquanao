#!/usr/bin/env bash
# Executed over SSH with a shell-quoted environment from production-env.py.
set -euo pipefail
umask 077
export COMPOSE_PROFILES=

# Validate Cloud access before changing the running stack. Disabled tracing is a rollback switch.
if [[ "$LANGFUSE_ENABLED" == true ]]; then
    python3 - <<'PY'
import base64, os, sys, urllib.request
key = base64.b64encode((os.environ['LANGFUSE_PUBLIC_KEY'] + ':' + os.environ['LANGFUSE_SECRET_KEY']).encode()).decode()
request = urllib.request.Request(os.environ['LANGFUSE_INGESTION_URL'].rstrip('/') + '/api/public/projects', headers={'Authorization': 'Basic ' + key})
try:
    with urllib.request.urlopen(request, timeout=15) as response:
        if response.status != 200:
            sys.exit('Langfuse Cloud project authentication failed')
except Exception as exc:
    sys.exit('Langfuse Cloud project authentication failed: ' + type(exc).__name__)
PY
fi

command -v docker >/dev/null
command -v git >/dev/null
registry="ghcr.io/${GITHUB_REPOSITORY,,}"
# Credentials are transient Git config, never stored in the repository remote URL.
repo_url="https://github.com/$GITHUB_REPOSITORY.git"
git_auth=$(printf 'x-access-token:%s' "$REPO_TOKEN" | base64 -w0)
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.https://github.com/.extraheader
export GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $git_auth"
if [[ ! -d "$DEPLOY_PATH/.git" ]]; then
    git clone --no-checkout "$repo_url" "$DEPLOY_PATH"
else
    if ! git -C "$DEPLOY_PATH" diff --quiet || ! git -C "$DEPLOY_PATH" diff --cached --quiet; then
        echo 'Deployment checkout contains tracked local edits' >&2
        exit 1
    fi
fi
cd "$DEPLOY_PATH"
git fetch "$repo_url" "$GITHUB_SHA"
unset GIT_CONFIG_COUNT GIT_CONFIG_KEY_0 GIT_CONFIG_VALUE_0 git_auth REPO_TOKEN
# Retain ignored data and volumes.
git checkout --detach "$GITHUB_SHA"
[[ "$(git rev-parse HEAD)" == "$GITHUB_SHA" ]]

# Write a Compose-compatible .env with literal single-quoted values.
registry_logged_in=false
trap 'if [[ "$registry_logged_in" == true ]]; then docker logout ghcr.io >/dev/null 2>&1 || true; fi; rm -f .env.next' EXIT
export APP_IMAGE="$registry/app:$GITHUB_SHA" CHAT_GRAPH_IMAGE="$registry/chat-graph:$GITHUB_SHA"
export RERANKER_IMAGE="$registry/reranker:$GITHUB_SHA" RAG_ML_IMAGE="$registry/rag-ml:$GITHUB_SHA"
export APP_ENV=production LANGFUSE_TRACE_CONTENT=false
python3 - <<'PY'
import os, pathlib
names = '''APP_ENV COMPOSE_PROFILES APP_IMAGE CHAT_GRAPH_IMAGE RERANKER_IMAGE RAG_ML_IMAGE LLM_API_KEY LLM_BASE_URL LLM_MODEL LLM_TIMEOUT MARIADB_ROOT_PASSWORD DB_PASS MARIADB_BUFFER_POOL_SIZE MCP_SERVICE_TOKEN CHAT_GRAPH_TOKEN LANGFUSE_ENABLED LANGFUSE_PUBLIC_URL LANGFUSE_INGESTION_URL LANGFUSE_PUBLIC_KEY LANGFUSE_SECRET_KEY LANGFUSE_TRACE_CONTENT'''.split()
path = pathlib.Path('.env.next')
with path.open('w') as f:
    for name in names:
        value = os.environ.get(name, '')
        if '\n' in value or '\r' in value:
            raise SystemExit('Invalid multiline setting: ' + name)
        f.write(name + "='" + value.replace("'", "\\'") + "'\n")
PY
compose=(docker compose -f docker-compose.yml -f docker-compose.production.yml)
export SHOP_ENV_FILE=.env.next
"${compose[@]}" --env-file .env.next config --quiet
services=$("${compose[@]}" --env-file .env.next config --services)
expected_services=$(printf '%s\n' app chat-graph db nginx qdrant rag-ml redis reranker)
[[ "$(sort <<< "$services")" == "$expected_services" ]]

# Pull exact CI-tested images before stopping any running container. Keep old tags for rollback.
printf '%s' "$REGISTRY_TOKEN" | docker login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
registry_logged_in=true
"${compose[@]}" --env-file .env.next pull nginx db redis qdrant app chat-graph rag-ml reranker
unset REGISTRY_TOKEN

printf '\n=== Baseline production metrics ===\n'
free -m
df -h /
docker system df
docker ps --filter name=shop_quan_ao_ --format '{{.Names}}' | xargs -r docker stats --no-stream
if [[ -f .env ]]; then cp -p .env .env.previous; fi
mv .env.next .env
unset SHOP_ENV_FILE

# Stopping (not deleting) legacy observability containers also preserves their data for rollback.
"${compose[@]}" --profile observability stop langfuse-web langfuse-worker langfuse-postgres langfuse-clickhouse langfuse-minio langfuse-redis
"${compose[@]}" stop app chat-graph nginx
started=$SECONDS
"${compose[@]}" up -d --no-build --wait --wait-timeout 180 db redis
"${compose[@]}" run --rm --no-deps app php scripts/run_database_migrations.php
"${compose[@]}" up -d --no-build --wait --wait-timeout 300
"${compose[@]}" exec -T rag-ml python -c 'import json,urllib.request; d=json.load(urllib.request.urlopen("http://localhost:8000/health",timeout=5)); assert d["runtime"]=="onnxruntime" and d["precision"]=="fp16" and d["embedding_model_loaded"] and d["reranker_model_loaded"]; print("rag-ml warmup_ms:",d["warmup_elapsed_ms"])'
"${compose[@]}" exec -T reranker python -c 'import urllib.request; urllib.request.urlopen("http://localhost:8000/health",timeout=5).read()'
"${compose[@]}" exec -T app php scripts/ingest_knowledge.php
printf 'Startup seconds: %s\n' "$((SECONDS-started))"
curl -fsS -o /dev/null -w 'Product API status=%{http_code} seconds=%{time_total}\n' 'http://localhost/api/products?limit=1'
refusal=$(curl -fsS -m 60 -X POST http://localhost/api/chatbot -H 'Content-Type: application/json' --data '{"message":"thêm áo này vào giỏ"}')
grep -q '"response_type":"refusal"' <<< "$refusal"
stream=$(curl -fsSN -m 90 -X POST http://localhost/api/chatbot/stream -H 'Content-Type: application/json' --data '{"message":"áo polo màu đỏ"}')
grep -q '^event: chat.complete' <<< "$stream"
# A busy response can also end with chat.complete; reject it.
! grep -q '"response_type":"busy"' <<< "$stream" || exit 1

printf '\n=== Production metrics ===\n'
free -m
df -h /
"${compose[@]}" ps
docker stats --no-stream $("${compose[@]}" ps -q)
for id in $("${compose[@]}" ps -q); do
    docker inspect --format '{{.Name}} OOM={{.State.OOMKilled}} restarts={{.RestartCount}}' "$id"
    [[ $(docker inspect --format '{{.State.OOMKilled}} {{.RestartCount}}' "$id") == 'false 0' ]]
done
printf '=== End production metrics ===\n'
