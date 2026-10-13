#!/bin/bash
## Read-only inventory of a Coolify host before a distribution migration.
## It changes nothing and prints names, digests, counts and checksums. Secret values are never printed.

set -u
export LC_ALL=C

SOURCE_DIR="${COOLIFY_SOURCE_DIR:-/data/coolify/source}"
ENV_FILE="$SOURCE_DIR/.env"
PUBLIC_ENV_KEYS="APP_ENV COOLIFY_VERSION LATEST_IMAGE REGISTRY_URL AUTOUPDATE APP_PORT SOKETI_PORT REVERB_PORT PUSHER_BACKEND_HOST PUSHER_BACKEND_PORT DB_DATABASE HORIZON_ENABLED SCHEDULER_ENABLED SSH_MUX_ENABLED"

section() {
    printf '\n## %s\n\n' "$1"
}

code() {
    printf '```\n'
    cat
    printf '```\n'
}

env_value() {
    [ -r "$ENV_FILE" ] || return 0
    grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d '=' -f2-
}

container_summary() {
    local container="$1"
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo "$container: not present"
        return
    fi
    docker inspect --format "{{.Name}}
  image reference: {{.Config.Image}}
  image id: {{.Image}}
  created: {{.Created}}
  state: {{.State.Status}} (health: {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}, restarts: {{.RestartCount}})
  compose project: {{index .Config.Labels \"com.docker.compose.project\"}}
  compose files: {{index .Config.Labels \"com.docker.compose.project.config_files\"}}" "$container"
    printf '  repo digests: %s\n' "$(docker image inspect --format '{{json .RepoDigests}}' "$(docker inspect --format '{{.Image}}' "$container")" 2>/dev/null)"
    printf '  mounts:\n'
    docker inspect --format '{{range .Mounts}}    {{.Type}} {{if .Name}}{{.Name}}{{else}}{{.Source}}{{end}} -> {{.Destination}} (rw={{.RW}})
{{end}}' "$container"
    printf '  networks: %s\n' "$(docker inspect --format '{{range $name, $net := .NetworkSettings.Networks}}{{$name}} {{end}}' "$container")"
}

printf '# Coolify host inventory\n\n'
printf -- '- Generated: %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
printf -- '- Host: %s\n' "$(hostname)"
printf -- '- Kernel: %s\n' "$(uname -sr)"
printf -- '- OS: %s\n' "$(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME")"
printf -- '- Docker: %s\n' "$(docker version --format '{{.Server.Version}}' 2>/dev/null)"
printf -- '- Docker Compose: %s\n' "$(docker compose version --short 2>/dev/null)"
printf -- '- Docker root: %s\n' "$(docker info --format '{{.DockerRootDir}}' 2>/dev/null)"

section "Coolify containers"
for container in coolify coolify-db coolify-redis coolify-realtime coolify-proxy coolify-sentinel; do
    container_summary "$container"
    echo
done | code

section "Environment variable names of the coolify container"
docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' coolify 2>/dev/null | cut -d '=' -f1 | sort -u | tr '\n' ' ' | fold -w 100 | code

section "Installation files"
if [ -d "$SOURCE_DIR" ]; then
    find "$SOURCE_DIR" -maxdepth 1 -type f -printf '%TY-%Tm-%Td %TH:%TM  %8s  %f\n' 2>/dev/null | sort -k3 | code
    printf '\nChecksums:\n\n'
    (cd "$SOURCE_DIR" && sha256sum docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml docker-compose.postgres-upgrade.yml upgrade.sh 2>/dev/null) | code
else
    echo "$SOURCE_DIR does not exist."
fi

section "docker-compose.custom.yml structure (keys and image lines only)"
if [ -f "$SOURCE_DIR/docker-compose.custom.yml" ]; then
    grep -nE '^[[:space:]]*([A-Za-z0-9_.-]+:[[:space:]]*$|image:)' "$SOURCE_DIR/docker-compose.custom.yml" | code
else
    echo "No docker-compose.custom.yml exists."
fi

section "Effective images of the Coolify stack"
if [ -r "$ENV_FILE" ]; then
    FILES=(-f "$SOURCE_DIR/docker-compose.yml" -f "$SOURCE_DIR/docker-compose.prod.yml")
    [ -f "$SOURCE_DIR/docker-compose.custom.yml" ] && FILES+=(-f "$SOURCE_DIR/docker-compose.custom.yml")
    [ -f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml" ] && FILES+=(-f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml")
    docker compose --env-file "$ENV_FILE" "${FILES[@]}" config --images 2>&1 | code
else
    echo "$ENV_FILE is not readable."
fi

section ".env keys and public values"
if [ -r "$ENV_FILE" ]; then
    printf 'Keys: '
    grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$ENV_FILE" | cut -d '=' -f1 | sort -u | tr '\n' ' '
    printf '\n\n'
    for key in $PUBLIC_ENV_KEYS; do
        value=$(env_value "$key")
        [ -n "$value" ] && printf -- '- %s=%s\n' "$key" "$value"
    done
    for key in APP_KEY APP_PREVIOUS_KEYS DB_PASSWORD REDIS_PASSWORD PUSHER_APP_SECRET; do
        if [ -n "$(env_value "$key")" ]; then
            printf -- '- %s: set (value not shown)\n' "$key"
        else
            printf -- '- %s: missing\n' "$key"
        fi
    done
    env_backups=$(find "$SOURCE_DIR" -maxdepth 1 -name '.env-*' 2>/dev/null | wc -l)
    printf -- '- .env backups from earlier upgrades: %s\n' "$env_backups"
fi

section "Volumes"
docker volume ls --format '{{.Name}}' | grep -E '^coolify' | while read -r volume; do
    mountpoint=$(docker volume inspect --format '{{.Mountpoint}}' "$volume")
    printf '%s  %s  %s\n' "$volume" "$mountpoint" "$(du -sh "$mountpoint" 2>/dev/null | cut -f1)"
done | code
printf 'Data directory sizes:\n\n'
du -sh /data/coolify/* 2>/dev/null | code

section "Networks"
docker network ls --format '{{.Name}} {{.Driver}} {{.Scope}}' | code
docker network inspect --format 'coolify subnet: {{range .IPAM.Config}}{{.Subnet}} {{end}} ipv6={{.EnableIPv6}} attachable={{.Attachable}}' coolify 2>/dev/null | code

section "Disk"
df -h / /data "$(docker info --format '{{.DockerRootDir}}' 2>/dev/null)" 2>/dev/null | code

section "Coolify database"
DB_USER=$(env_value DB_USERNAME)
DB_NAME=$(env_value DB_DATABASE)
psql_value() {
    docker exec coolify-db psql -U "${DB_USER:-coolify}" -d "${DB_NAME:-coolify}" -tAc "$1" 2>/dev/null
}
{
    printf 'server version: %s\n' "$(psql_value 'show server_version')"
    printf 'database size: %s\n' "$(psql_value "select pg_size_pretty(pg_database_size(current_database()))")"
    printf 'applied migrations: %s (latest: %s)\n' "$(psql_value 'select count(*) from migrations')" "$(psql_value 'select max(migration) from migrations')"
    for table in teams users projects environments servers applications services application_deployment_queues shared_environment_variables environment_variables scheduled_database_backups; do
        printf '%s: %s rows\n' "$table" "$(psql_value "select count(*) from $table")"
    done
    printf 'deployments queued or in progress: %s\n' "$(psql_value "select count(*) from application_deployment_queues where status in ('queued','in_progress')")"
    printf 'instance settings: %s\n' "$(psql_value "select concat('auto_update=', is_auto_update_enabled, ' update_check=', update_check_frequency, ' auto_update_frequency=', auto_update_frequency, ' new_version_available=', new_version_available, ' registry=', coalesce(docker_registry_url, 'n/a'), ' helper=', helper_version) from instance_settings where id = 0")"
    printf 'coolify-db backup schedules: %s\n' "$(psql_value "select string_agg(concat('enabled=', enabled, ' frequency=', frequency, ' s3=', save_s3), '; ') from scheduled_database_backups where database_type like '%StandalonePostgresql' and database_id = 0")"
    printf 'latest coolify-db backup executions: %s\n' "$(psql_value "select string_agg(concat(status, '@', coalesce(finished_at, created_at)), ', ') from (select e.status, e.finished_at, e.created_at from scheduled_database_backup_executions e join scheduled_database_backups b on b.id = e.scheduled_database_backup_id where b.database_type like '%StandalonePostgresql' and b.database_id = 0 order by e.id desc limit 5) recent")"
} | code

section "Redis"
REDIS_SECRET=$(env_value REDIS_PASSWORD)
docker exec -e REDISCLI_AUTH="$REDIS_SECRET" coolify-redis redis-cli INFO 2>/dev/null | grep -E '^(redis_version|used_memory_human|maxmemory_human|maxmemory_policy|evicted_keys|aof_enabled|rdb_last_bgsave_status|rdb_last_save_time|connected_clients):' | tr -d '\r' | code
unset REDIS_SECRET

section "Upgrade state"
{
    printf 'upgrade status file: %s\n' "$(cat "$SOURCE_DIR/.upgrade-status" 2>/dev/null || echo none)"
    printf 'distribution result file: %s\n' "$(cut -d '|' -f1-2,4 "$SOURCE_DIR/.upgrade-distribution-result" 2>/dev/null || echo none)"
    printf 'recent upgrade logs:\n'
    find "$SOURCE_DIR" -maxdepth 1 -name 'upgrade*.log' -printf '  %TY-%Tm-%Td %TH:%TM %f\n' 2>/dev/null | sort | tail -n 5
} | code

section "Backups on this host"
find /data/coolify/backups -maxdepth 4 -type f -printf '%TY-%Tm-%Td %TH:%TM  %10s  %P\n' 2>/dev/null | sort | tail -n 15 | code

printf '\nInventory complete. Nothing was changed.\n'
