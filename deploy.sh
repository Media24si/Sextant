#!/usr/bin/env bash
#
# Deploy Sextant. Run on the server as the app user (the one Horizon and the
# scheduler run as), from any directory:
#
#   ./deploy.sh
#
# Optional environment:
#   DEPLOY_BRANCH     branch to deploy (default: main)
#   PHP_FPM_SERVICE   e.g. php8.4-fpm; reloaded with sudo afterwards so OPcache
#                     drops the old code (needed when opcache.validate_timestamps=0)
#
# First-time setup (create-admin, proxies:refresh, rdap:refresh-bootstrap) is in
# the README; this script only does what every release needs.

set -Eeuo pipefail

main() {
    local branch="${DEPLOY_BRANCH:-main}"

    cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"

    trap 'fail "$BASH_COMMAND"' ERR

    if [[ "${1:-}" != "--pulled" ]]; then
        exec 9>"$(git rev-parse --git-dir)/deploy.lock"
        flock -n 9 || fail "another deploy is running"

        pull "$branch"

        # Bash reads a script as it runs it, so a deploy.sh changed by the pull
        # is started afresh. The lock on fd 9 carries over through exec.
        if ! git diff --quiet "$previous_commit" HEAD -- deploy.sh; then
            step "deploy.sh changed, restarting it"
            PREVIOUS_COMMIT="$previous_commit" exec ./deploy.sh --pulled
        fi
    else
        previous_commit="${PREVIOUS_COMMIT:-$(git rev-parse HEAD)}"
    fi

    check_environment

    step "Installing PHP dependencies"
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

    # Not optimize:clear: that also flushes the Redis cache, which holds
    # scheduler mutexes and check state.
    step "Clearing cached config, routes, events and views"
    php artisan config:clear
    php artisan route:clear
    php artisan event:clear
    php artisan view:clear

    step "Building frontend assets"
    npm ci --no-audit --no-fund
    npm run build

    step "Migrating the database"
    php artisan migrate --force

    step "Caching config, routes, events and views"
    php artisan optimize

    step "Validating the checker registry"
    php artisan sextant:checkers

    step "Matching repositories to GitHub links"
    php artisan repositories:sync

    step "Restarting Horizon and the scheduler"
    php artisan horizon:terminate
    php artisan schedule:interrupt

    if [[ -n "${PHP_FPM_SERVICE:-}" ]]; then
        step "Reloading $PHP_FPM_SERVICE"
        sudo systemctl reload "$PHP_FPM_SERVICE"
    fi

    step "Deployed $(git log -1 --format='%h %s')"
    if [[ "$previous_commit" != "$(git rev-parse HEAD)" ]]; then
        git --no-pager log --oneline "$previous_commit..HEAD"
    fi
}

pull() {
    local branch="$1"
    local current_branch

    current_branch="$(git rev-parse --abbrev-ref HEAD)"
    [[ "$current_branch" == "$branch" ]] || fail "checked out on '$current_branch', expected '$branch'"

    git diff --quiet && git diff --cached --quiet || fail "the working tree has local changes; commit or discard them first"

    previous_commit="$(git rev-parse HEAD)"

    step "Pulling $branch"
    git fetch --prune origin "$branch"
    git merge --ff-only "origin/$branch"
}

# A production .env, so a server copied from .env.example doesn't run with
# APP_ENV=local (no password rules, debug pages) and APP_DEBUG=true.
check_environment() {
    local app_env app_debug mailer

    [[ -f .env ]] || fail ".env is missing; copy .env.example and see the README"

    app_env="$(env_value APP_ENV)"
    app_debug="$(env_value APP_DEBUG)"
    mailer="$(env_value MAIL_MAILER)"

    [[ "$app_env" == "production" ]] || fail "APP_ENV is '${app_env}' in .env; set APP_ENV=production"
    [[ "$app_debug" == "false" ]] || fail "APP_DEBUG is '${app_debug}' in .env; set APP_DEBUG=false"

    if [[ -z "$mailer" || "$mailer" == "log" ]]; then
        warn "MAIL_MAILER is log: password resets are turned off until mail is set up"
    fi
}

# The last value of a key in .env, without quotes.
env_value() {
    sed -n "s/^$1=//p" .env | tail -n 1 | tr -d "\"'"
}

warn() {
    printf '\n\033[1;33mWarning: %s\033[0m\n' "$1" >&2
}

step() {
    printf '\n\033[1;34m==> %s\033[0m\n' "$1"
}

fail() {
    printf '\n\033[1;31mDeploy failed: %s\033[0m\n' "$1" >&2
    exit 1
}

main "$@"
