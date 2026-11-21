#!/usr/bin/env bash
#
# Sextant versions collector (SPEC §11.3).
#
# Reads the versions of the OS, kernel, nginx, PHP, MySQL and OpenSSL and
# writes them to /var/lib/sextant/versions.json, which the server's
# versions.php hands to Sextant. Needs bash and coreutils only (no jq).
# Missing software is written as null; the script never fails because a
# program isn't installed.
#
# Install as root:
#   install -m 0755 sextant-collect.sh /usr/local/bin/sextant-collect
#   echo '17 * * * * root /usr/local/bin/sextant-collect' > /etc/cron.d/sextant
#   /usr/local/bin/sextant-collect

set -u

# The group the web server's PHP runs as; it must be able to read the file.
SEXTANT_GROUP="${SEXTANT_GROUP:-www-data}"
# Where versions.json goes; versions.php reads it from here.
SEXTANT_DIR="${SEXTANT_DIR:-/var/lib/sextant}"
SEXTANT_FILE="${SEXTANT_DIR}/versions.json"

# JSON string, or null for an empty value.
json_string() {
    local value="$1"

    if [ -z "$value" ]; then
        printf 'null'
        return
    fi

    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//$'\n'/ }"
    value="${value//$'\r'/}"
    value="${value//$'\t'/ }"
    printf '"%s"' "$value"
}

# The installed version of a Debian package, or nothing.
package_version() {
    if command -v dpkg-query >/dev/null 2>&1; then
        # shellcheck disable=SC2016 # ${Version} is dpkg-query's field, not a shell variable.
        dpkg-query -W -f='${Version}' "$1" 2>/dev/null || true
    fi
}

# The first package of a pattern that's installed, e.g. mysql-server-core-*.
first_package_version() {
    if command -v dpkg-query >/dev/null 2>&1; then
        # shellcheck disable=SC2016 # dpkg-query's fields, not shell variables.
        dpkg-query -W -f='${Status} ${Version}\n' "$1" 2>/dev/null \
            | while read -r _ _ status version; do
                if [ "$status" = "installed" ]; then
                    printf '%s' "$version"
                    break
                fi
            done
    fi
}

# The leading version number in a string, e.g. "nginx/1.26.2 (Ubuntu)" -> 1.26.2.
leading_version() {
    local text="$1"

    if [[ "$text" =~ ([0-9]+(\.[0-9]+)+[a-z]?) ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
    fi
}

# {"version": …, "package": …}, or null when there's no version.
version_object() {
    local version="$1" package="$2"

    if [ -z "$version" ]; then
        printf 'null'
        return
    fi

    printf '{ "version": %s, "package": %s }' "$(json_string "$version")" "$(json_string "$package")"
}

os_json() {
    local id="" version_id="" pretty_name=""

    if [ -r /etc/os-release ]; then
        # shellcheck disable=SC1091
        id="$(. /etc/os-release && printf '%s' "${ID:-}")"
        # shellcheck disable=SC1091
        version_id="$(. /etc/os-release && printf '%s' "${VERSION_ID:-}")"
        # shellcheck disable=SC1091
        pretty_name="$(. /etc/os-release && printf '%s' "${PRETTY_NAME:-}")"
    fi

    if [ -z "$id" ]; then
        printf 'null'
        return
    fi

    printf '{ "id": %s, "version_id": %s, "pretty_name": %s }' \
        "$(json_string "$id")" "$(json_string "$version_id")" "$(json_string "$pretty_name")"
}

kernel_json() {
    printf '{ "version": %s }' "$(json_string "$(uname -r 2>/dev/null || true)")"
}

nginx_json() {
    local version=""

    if command -v nginx >/dev/null 2>&1; then
        version="$(leading_version "$(nginx -v 2>&1 || true)")"
    fi

    version_object "$version" "$(package_version nginx)"
}

# One entry per PHP branch found as /usr/bin/phpX.Y or /usr/sbin/php-fpmX.Y.
php_json() {
    local branches=() seen=" " binary branch entries="" first=1

    for binary in /usr/bin/php[0-9].[0-9] /usr/sbin/php-fpm[0-9].[0-9]; do
        [ -x "$binary" ] || continue
        branch="${binary##*php}"
        branch="${branch#-fpm}"

        if [[ "$seen" != *" ${branch} "* ]]; then
            branches+=("$branch")
            seen+="${branch} "
        fi
    done

    for branch in "${branches[@]+"${branches[@]}"}"; do
        local version="" cli=false fpm=false package=""

        if [ -x "/usr/bin/php${branch}" ]; then
            cli=true
            version="$("/usr/bin/php${branch}" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
            package="$(package_version "php${branch}-cli")"
        fi

        if [ -x "/usr/sbin/php-fpm${branch}" ]; then
            fpm=true

            if [ -z "$version" ]; then
                version="$(leading_version "$("/usr/sbin/php-fpm${branch}" -v 2>/dev/null | head -n 1 || true)")"
            fi

            if [ -z "$package" ]; then
                package="$(package_version "php${branch}-fpm")"
            fi
        fi

        [ -n "$version" ] || continue

        if [ "$first" -eq 0 ]; then
            entries+=", "
        fi

        first=0
        entries+="$(printf '{ "branch": %s, "version": %s, "cli": %s, "fpm": %s, "package": %s }' \
            "$(json_string "$branch")" "$(json_string "$version")" "$cli" "$fpm" "$(json_string "$package")")"
    done

    printf '[%s]' "$entries"
}

mysql_json() {
    local version=""

    if command -v mysqld >/dev/null 2>&1; then
        version="$(leading_version "$(mysqld --version 2>/dev/null || true)")"
    fi

    version_object "$version" "$(first_package_version 'mysql-server-core-*')"
}

openssl_json() {
    local version=""

    if command -v openssl >/dev/null 2>&1; then
        version="$(leading_version "$(openssl version 2>/dev/null || true)")"
    fi

    version_object "$version" "$(package_version openssl)"
}

main() {
    local temp

    mkdir -p "$SEXTANT_DIR" || exit 1
    temp="$(mktemp "${SEXTANT_DIR}/versions.json.XXXXXX")" || exit 1

    {
        printf '{\n'
        printf '  "schema": 1,\n'
        printf '  "generated_at": %s,\n' "$(json_string "$(date -u +%Y-%m-%dT%H:%M:%SZ)")"
        printf '  "hostname": %s,\n' "$(json_string "$(hostname 2>/dev/null || true)")"
        printf '  "os": %s,\n' "$(os_json)"
        printf '  "kernel": %s,\n' "$(kernel_json)"
        printf '  "nginx": %s,\n' "$(nginx_json)"
        printf '  "php": %s,\n' "$(php_json)"
        printf '  "mysql": %s,\n' "$(mysql_json)"
        printf '  "openssl": %s\n' "$(openssl_json)"
        printf '}\n'
    } >"$temp"

    chmod 0640 "$temp"
    chgrp "$SEXTANT_GROUP" "$temp" 2>/dev/null || true

    # Atomic: readers see the old file or the new one, never half of one.
    mv -f "$temp" "$SEXTANT_FILE"
}

main "$@"
