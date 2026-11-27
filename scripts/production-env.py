#!/usr/bin/env python3
"""Create a shell-quoted environment prefix for the SSH deployment script."""
import os
import shlex
import sys
from urllib.parse import urlsplit

REQUIRED = (
    'DEPLOY_PATH', 'REPO_TOKEN', 'REGISTRY_TOKEN', 'GITHUB_REPOSITORY', 'GITHUB_SHA',
    'GITHUB_ACTOR', 'LLM_API_KEY', 'LLM_BASE_URL', 'LLM_MODEL', 'LLM_TIMEOUT',
    'MARIADB_ROOT_PASSWORD', 'DB_PASS', 'MARIADB_BUFFER_POOL_SIZE',
    'MCP_SERVICE_TOKEN', 'CHAT_GRAPH_TOKEN',
)
CLOUD = ('LANGFUSE_PUBLIC_URL', 'LANGFUSE_INGESTION_URL', 'LANGFUSE_PUBLIC_KEY', 'LANGFUSE_SECRET_KEY')
CLOUD_HOSTS = {'cloud.langfuse.com', 'us.cloud.langfuse.com', 'jp.cloud.langfuse.com', 'hipaa.cloud.langfuse.com'}


def main():
    enabled = os.environ.get('LANGFUSE_ENABLED', 'true').lower()
    if enabled not in ('true', 'false'):
        sys.exit('LANGFUSE_ENABLED must be true or false')
    required = REQUIRED + (CLOUD if enabled == 'true' else ())
    for name in required:
        if not os.environ.get(name):
            sys.exit(f'Missing required setting: {name}')
    if not os.environ['DEPLOY_PATH'].startswith('/'):
        sys.exit('DEPLOY_PATH must be absolute')
    if enabled == 'true':
        for name in CLOUD[:2]:
            url = urlsplit(os.environ[name])
            if url.scheme != 'https' or url.hostname not in CLOUD_HOSTS or url.username or url.password or url.port or url.path not in ('', '/') or url.query or url.fragment:
                sys.exit(f'{name} must be a Langfuse Cloud HTTPS origin')
    for name in REQUIRED + CLOUD:
        print(f'export {name}={shlex.quote(os.environ.get(name, ""))}')
    print(f'export LANGFUSE_ENABLED={shlex.quote(enabled)}')


if __name__ == '__main__':
    main()
