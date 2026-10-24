#!/bin/sh
set -eu

ACTION=${1:---preflight}
shift || true
HOSTNAME_VALUE=''
TLS_CERT=''
TLS_KEY=''
RELEASE_VERSION=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --hostname) [ "$#" -ge 2 ] || { printf '%s\n' 'Missing --hostname value.' >&2; exit 2; }; HOSTNAME_VALUE=$2; shift 2;;
        --tls-cert) [ "$#" -ge 2 ] || { printf '%s\n' 'Missing --tls-cert value.' >&2; exit 2; }; TLS_CERT=$2; shift 2;;
        --tls-key) [ "$#" -ge 2 ] || { printf '%s\n' 'Missing --tls-key value.' >&2; exit 2; }; TLS_KEY=$2; shift 2;;
        --release-version) [ "$#" -ge 2 ] || { printf '%s\n' 'Missing --release-version value.' >&2; exit 2; }; RELEASE_VERSION=$2; shift 2;;
        *) printf 'Unknown option: %s\n' "$1" >&2; exit 2;;
    esac
done

SOURCE_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
APP_ROOT=/opt/respawn-pond
STATE_ROOT=/var/lib/respawn-pond
CONFIG_ROOT=/etc/respawn-pond
PHP_VERSION=8.4
SERVICE_USER=respawn-pond
SERVICE_GROUP=www-data
NGINX_SITE=/etc/nginx/sites-available/respawn-pond.conf
NGINX_LINK=/etc/nginx/sites-enabled/respawn-pond.conf
FPM_POOL=$CONFIG_ROOT/pool.conf
FPM_MASTER=$CONFIG_ROOT/php-fpm.conf
FPM_SERVICE=/etc/systemd/system/respawn-pond-php-fpm.service
POLICY_CREATED=0

VERSION=$(sed -n 's/.*<version>\([^<]*\)<\/version>.*/\1/p' "$SOURCE_ROOT/module/module.xml" | head -n 1)
case "$VERSION" in *[!0-9.]*|'') printf '%s\n' 'Unable to read a safe Respawn version.' >&2; exit 2;; esac

cleanup_policy() {
    if [ "$POLICY_CREATED" -eq 1 ]; then rm -f /usr/sbin/policy-rc.d; POLICY_CREATED=0; fi
}
trap cleanup_policy EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

is_debian_13() {
    [ -r /etc/os-release ] || return 1
    . /etc/os-release
    [ "${ID:-}" = debian ] && [ "${VERSION_ID:-}" = 13 ]
}

package_installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'; }

check_service_account() {
    record=$(getent passwd "$SERVICE_USER" || true)
    if [ -z "$record" ]; then printf '%s\n' 'Service account: respawn-pond will be created as an unprivileged system user'; return 0; fi
    uid=$(printf '%s\n' "$record" | awk -F: '{print $3}')
    shell=$(printf '%s\n' "$record" | awk -F: '{print $7}')
    case "$uid" in ''|*[!0-9]*|0) printf '%s\n' 'Service account: existing respawn-pond identity is privileged/invalid'; return 1;; esac
    case "$shell" in */nologin|*/false) ;; *) printf '%s\n' 'Service account: existing respawn-pond identity has a login shell'; return 1;; esac
    if ! getent group "$SERVICE_USER" >/dev/null; then
        printf '%s\n' 'Service account: dedicated respawn-pond group is missing'; return 1
    fi
    printf '%s\n' 'Service account: existing identity is non-root, non-login and has its dedicated group'
}

preflight() {
    failures=0
    if is_debian_13; then printf '%s\n' 'OS: Debian 13 (supported)'; else printf '%s\n' 'OS: unsupported (installer requires Debian 13)'; failures=1; fi
    for package in nginx unzip php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-curl openssl ca-certificates; do
        if package_installed "$package"; then printf 'Dependency %s: installed\n' "$package";
        else printf 'Dependency %s: missing\n' "$package"; fi
    done
    if command -v ss >/dev/null 2>&1; then
        if ss -H -ltnp 2>/dev/null | grep -qE '(:443[[:space:]]|:443$)'; then
            printf '%s\n' 'HTTPS port 443: already has a listener; review before enabling the Pond site.'
        else printf '%s\n' 'HTTPS port 443: no listener detected'; fi
    else printf '%s\n' 'HTTPS port 443: cannot check (ss is unavailable)'; fi
    if [ -e "$NGINX_SITE" ] && ! grep -q 'Managed by Respawn Pond installer' "$NGINX_SITE"; then
        printf '%s\n' 'Nginx site: conflicting unmarked file exists'; failures=1
    else printf '%s\n' 'Nginx site: no conflicting file'; fi
    if [ -e "$FPM_POOL" ] && ! grep -q 'Managed by Respawn Pond installer' "$FPM_POOL"; then
        printf '%s\n' 'PHP-FPM pool: conflicting unmarked file exists'; failures=1
    else printf '%s\n' 'PHP-FPM pool: no conflicting file'; fi
    unit_conflict=0
    for unit_file in "$FPM_SERVICE" /usr/lib/systemd/system/respawn-pond-php-fpm.service \
        /lib/systemd/system/respawn-pond-php-fpm.service /run/systemd/system/respawn-pond-php-fpm.service; do
        if [ -e "$unit_file" ] || [ -L "$unit_file" ]; then unit_conflict=1; fi
    done
    if [ -e "$FPM_SERVICE" ] && ! grep -q 'Managed by Respawn Pond installer' "$FPM_SERVICE"; then
        printf '%s\n' 'Pond PHP-FPM service: conflicting unmarked unit exists'; failures=1
    elif [ ! -e "$FPM_SERVICE" ] && [ "$unit_conflict" -eq 1 ]; then
        printf '%s\n' 'Pond PHP-FPM service: an existing system unit would be shadowed'; failures=1
    else printf '%s\n' 'Pond PHP-FPM service: no conflicting unit'; fi
    if [ -e "$FPM_MASTER" ] && ! grep -q 'Managed by Respawn Pond installer' "$FPM_MASTER"; then
        printf '%s\n' 'Pond PHP-FPM master config: conflicting unmarked file exists'; failures=1
    else printf '%s\n' 'Pond PHP-FPM master config: no conflicting file'; fi
    if [ -e /usr/sbin/policy-rc.d ] || [ -L /usr/sbin/policy-rc.d ]; then
        printf '%s\n' 'Service policy: existing policy-rc.d requires administrator-managed dependency installation'; failures=1
    else printf '%s\n' 'Service policy: installer can suppress package-triggered service starts'; fi
    check_service_account || failures=1
    if [ -e "$APP_ROOT/current" ] && [ ! -L "$APP_ROOT/current" ]; then
        printf '%s\n' 'Application path: conflicting non-symlink current path exists'; failures=1
    else printf '%s\n' 'Application path: no conflicting current path'; fi
    if [ -L "$APP_ROOT" ]; then
        printf '%s\n' 'Application root: symlinked root path is unsafe'; failures=1
    fi
    if { [ -e "$CONFIG_ROOT/master.key" ] && [ ! -e "$STATE_ROOT/state/pond.sqlite" ]; } \
        || { [ -e "$STATE_ROOT/state/pond.sqlite" ] && [ ! -e "$CONFIG_ROOT/master.key" ]; }; then
        printf '%s\n' 'Storage: key/database pair is incomplete; installer will refuse initialisation'; failures=1
    elif [ -e "$CONFIG_ROOT/master.key" ] && [ -e "$STATE_ROOT/state/pond.sqlite" ]; then
        printf '%s\n' 'Storage: existing database/key pair will be preserved'
    else printf '%s\n' 'Storage: no existing database/key pair'; fi
    available=$(df -Pk /opt 2>/dev/null | awk 'NR==2 {print $4}')
    case "$available" in ''|*[!0-9]*) printf '%s\n' 'Storage: unable to determine available space'; failures=1;; *)
        if [ "$available" -lt 102400 ]; then printf '%s\n' 'Storage: less than 100 MiB available under /opt'; failures=1;
        else printf 'Storage: %s KiB available under /opt\n' "$available"; fi;; esac
    printf '%s\n' 'Changes made: no'
    return "$failures"
}

require_root() { [ "$(id -u)" -eq 0 ] || { printf '%s\n' 'Run this action as root.' >&2; exit 2; }; }

validate_tls_arguments() {
    [ -n "$HOSTNAME_VALUE" ] && printf '%s' "$HOSTNAME_VALUE" | grep -qE '^[A-Za-z0-9.-]+$' \
        || { printf '%s\n' 'HTTPS configuration requires a valid explicit --hostname.' >&2; exit 2; }
    for file in "$TLS_CERT" "$TLS_KEY"; do
        case "$file" in /*) ;; *) printf '%s\n' 'TLS certificate and key paths must be absolute.' >&2; exit 2;; esac
        printf '%s' "$file" | grep -qE '^/[A-Za-z0-9_./-]+$' || { printf '%s\n' 'TLS paths contain unsupported characters.' >&2; exit 2; }
        resolved=$(readlink -f "$file" 2>/dev/null || true)
        [ -n "$resolved" ] && [ -f "$resolved" ] || { printf 'TLS file unavailable or unsafe: %s\n' "$file" >&2; exit 2; }
    done
    openssl x509 -in "$TLS_CERT" -noout -checkhost "$HOSTNAME_VALUE" >/dev/null 2>&1 \
        || { printf '%s\n' 'TLS certificate does not validate for the configured hostname.' >&2; exit 2; }
    openssl pkey -in "$TLS_KEY" -passin pass: -noout >/dev/null 2>&1 \
        || { printf '%s\n' 'TLS private key is invalid or encrypted; configure an unencrypted, protected Nginx key file.' >&2; exit 2; }
}

check_nginx_hostname_conflict() {
    for existing in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
        [ -f "$existing" ] || continue
        [ "$(readlink -f "$existing" 2>/dev/null || true)" = "$(readlink -f "$NGINX_SITE" 2>/dev/null || true)" ] && continue
        if awk -v hostname="$HOSTNAME_VALUE" '
            $1 == "server_name" {
                for (i=2; i<=NF; i++) { value=$i; sub(/[;#].*/, "", value); if (value == hostname) found=1 }
            }
            END { exit !found }
        ' "$existing"; then
            printf 'Hostname %s is already configured in %s; refusing to create a competing site.\n' "$HOSTNAME_VALUE" "$existing" >&2
            exit 2
        fi
    done
}

install_dependencies() {
    nginx_was_installed=0
    package_installed nginx && nginx_was_installed=1 || true
    if [ -e /usr/sbin/policy-rc.d ] || [ -L /usr/sbin/policy-rc.d ]; then
        printf '%s\n' 'An administrator policy-rc.d already exists. Install the documented Debian packages under that host policy, then rerun the Pond installer.' >&2
        exit 2
    fi
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d
    chmod 0755 /usr/sbin/policy-rc.d
    POLICY_CREATED=1
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-upgrade nginx unzip php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-curl openssl ca-certificates
    cleanup_policy
    if [ "$nginx_was_installed" -eq 0 ] && [ -L /etc/nginx/sites-enabled/default ] \
        && [ "$(readlink /etc/nginx/sites-enabled/default)" = '../sites-available/default' ]; then
        rm /etc/nginx/sites-enabled/default
    fi
}

write_managed_config() {
    source_file=$1
    destination=$2
    shift 2
    if [ -e "$destination" ] && ! grep -q 'Managed by Respawn Pond installer' "$destination"; then
        printf 'Refusing to replace unmarked host configuration: %s\n' "$destination" >&2
        exit 2
    fi
    temporary=$destination.tmp.$$
    sed "$@" "$source_file" > "$temporary"
    chmod 0644 "$temporary"
    chown root:root "$temporary"
    mv -f "$temporary" "$destination"
}

install_pond() {
    require_root
    is_debian_13 || { printf '%s\n' 'Installation is supported only on Debian 13.' >&2; exit 2; }
    [ -n "$HOSTNAME_VALUE" ] || { printf '%s\n' 'Pass explicit --hostname, --tls-cert and --tls-key values. The Nginx site will remain disabled.' >&2; exit 2; }
    validate_tls_arguments
    preflight || { printf '%s\n' 'Preflight found a blocking host conflict; resolve it before installation.' >&2; exit 2; }
    check_nginx_hostname_conflict
    install_dependencies
    check_service_account >/dev/null || { printf '%s\n' 'Refusing unsafe existing Pond service identity.' >&2; exit 2; }
    if ! getent passwd "$SERVICE_USER" >/dev/null; then
        useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin "$SERVICE_USER"
    fi
    install -d -m 0755 -o root -g root "$APP_ROOT/releases"
    release=$APP_ROOT/releases/$VERSION
    current=$APP_ROOT/current
    if [ -d "$release" ]; then
        [ -f "$release/.respawn-pond-release" ] && [ "$(cat "$release/.respawn-pond-release")" = "$VERSION" ] \
            || { printf 'Release %s exists without a valid Pond release marker; refusing to use it.\n' "$VERSION" >&2; exit 2; }
    else
        stage=$APP_ROOT/releases/.staging-$VERSION-$$
        install -d -m 0755 -o root -g root "$stage"
        for directory in pond module contracts; do cp -a "$SOURCE_ROOT/$directory" "$stage/"; done
        install -d -m 0755 "$stage/tools"
        cp -a "$SOURCE_ROOT/tools/preflight.php" "$stage/tools/preflight.php"
        cp -a "$SOURCE_ROOT/LICENSE" "$stage/LICENSE"
        printf '%s\n' "$VERSION" > "$stage/.respawn-pond-release"
        chown -R root:root "$stage"
        mv "$stage" "$release"
    fi
    if [ -e "$current" ] && [ ! -L "$current" ]; then
        printf 'Refusing to replace non-symlink application path: %s\n' "$current" >&2
        exit 2
    fi
    next_link=$APP_ROOT/.current-$VERSION-$$
    ln -s "$release" "$next_link"
    mv -Tf "$next_link" "$current"

    install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_ROOT"
    install -d -m 0700 -o "$SERVICE_USER" -g "$SERVICE_USER" "$STATE_ROOT"
    install -d -m 0700 -o "$SERVICE_USER" -g "$SERVICE_USER" "$STATE_ROOT/state"
    install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_GROUP" /run/respawn-pond

    write_managed_config "$current/pond/php-fpm.conf.example" "$FPM_POOL" \
        -e "s|@POND_STATE@|$STATE_ROOT/state|g" -e "s|@POND_KEY@|$CONFIG_ROOT/master.key|g" \
        -e '1i; Managed by Respawn Pond installer'
    write_managed_config "$current/pond/php-fpm-master.conf.example" "$FPM_MASTER" \
        -e "s|@POND_POOL@|$FPM_POOL|g" -e "s|@POND_PID@|/run/respawn-pond/php-fpm.pid|g" \
        -e '1i; Managed by Respawn Pond installer'
    write_managed_config "$current/pond/respawn-pond-php-fpm.service.example" "$FPM_SERVICE" \
        -e "s|@POND_FPM_CONFIG@|$FPM_MASTER|g" -e '1i# Managed by Respawn Pond installer'
    write_managed_config "$current/pond/nginx.conf.example" "$NGINX_SITE" \
        -e "s|@POND_HOST@|$HOSTNAME_VALUE|g" -e "s|@POND_CERT@|$TLS_CERT|g" \
        -e "s|@POND_TLS_KEY@|$TLS_KEY|g" -e "s|@POND_SCRIPT@|$current/pond/public/index.php|g" \
        -e '1i# Managed by Respawn Pond installer'

    if [ -e "$CONFIG_ROOT/master.key" ] || [ -e "$STATE_ROOT/state/pond.sqlite" ]; then
        [ -f "$CONFIG_ROOT/master.key" ] && [ -f "$STATE_ROOT/state/pond.sqlite" ] \
            || { printf '%s\n' 'Only part of Pond cryptographic/storage state exists; refusing automatic reinitialisation.' >&2; exit 2; }
        chown "$SERVICE_USER:$SERVICE_USER" "$CONFIG_ROOT/master.key" "$STATE_ROOT/state/pond.sqlite"
        chmod 0600 "$CONFIG_ROOT/master.key" "$STATE_ROOT/state/pond.sqlite"
    else
        runuser -u "$SERVICE_USER" -- php "$current/pond/bin/pond.php" init "$STATE_ROOT/state" "$CONFIG_ROOT/master.key"
    fi
    php-fpm8.4 -t --fpm-config "$FPM_MASTER"
    nginx -t
    systemctl daemon-reload
    systemctl enable --now respawn-pond-php-fpm.service
    if [ -L "$NGINX_LINK" ] && [ "$(readlink "$NGINX_LINK")" = "$NGINX_SITE" ] \
        && systemctl is-active --quiet nginx; then
        systemctl reload nginx
    fi
    if [ -L "$NGINX_LINK" ] && [ "$(readlink "$NGINX_LINK")" = "$NGINX_SITE" ]; then
        printf 'Installed Respawn Pond %s; previously enabled Nginx site remains enabled. No firewall rules were changed.\n' "$VERSION"
    else
        printf 'Installed Respawn Pond %s. Nginx site is prepared but disabled; enable it explicitly with --enable-https. No firewall rules were changed.\n' "$VERSION"
    fi
}

rollback_release() {
    require_root
    case "$RELEASE_VERSION" in ''|*[!0-9.]*) printf '%s\n' 'Pass --release-version with an installed numeric release.' >&2; exit 2;; esac
    release=$APP_ROOT/releases/$RELEASE_VERSION
    [ -d "$release" ] && [ ! -L "$release" ] \
        || { printf 'Installed Pond release not found: %s\n' "$RELEASE_VERSION" >&2; exit 2; }
    current=$APP_ROOT/current
    [ -L "$current" ] || { printf '%s\n' 'Pond current release link is missing or unsafe.' >&2; exit 2; }
    next_link=$APP_ROOT/.current-rollback-$$
    ln -s "$release" "$next_link"
    mv -Tf "$next_link" "$current"
    systemctl restart respawn-pond-php-fpm.service
    printf 'Rolled back Pond application code to %s. Database and key were not changed.\n' "$RELEASE_VERSION"
}

enable_https() {
    require_root
    [ -f "$NGINX_SITE" ] && grep -q 'Managed by Respawn Pond installer' "$NGINX_SITE" \
        || { printf '%s\n' 'Managed Pond Nginx site not found; run --install first.' >&2; exit 2; }
    if [ -e "$NGINX_LINK" ] || [ -L "$NGINX_LINK" ]; then
        [ -L "$NGINX_LINK" ] && [ "$(readlink "$NGINX_LINK")" = "$NGINX_SITE" ] \
            || { printf '%s\n' 'Refusing to replace an existing Nginx site entry.' >&2; exit 2; }
    else
        ln -s "$NGINX_SITE" "$NGINX_LINK"
    fi
    if ! nginx -t; then rm -f "$NGINX_LINK"; exit 1; fi
    if systemctl is-active --quiet nginx; then systemctl reload nginx; else systemctl enable --now nginx; fi
    printf '%s\n' 'Pond HTTPS site enabled. No firewall rules were changed; restrict access using administrator-managed network policy.'
}

disable_https() {
    require_root
    if [ -L "$NGINX_LINK" ] && [ "$(readlink "$NGINX_LINK")" = "$NGINX_SITE" ]; then rm "$NGINX_LINK"; fi
    nginx -t
    if systemctl is-active --quiet nginx; then systemctl reload nginx; fi
    printf '%s\n' 'Pond HTTPS site disabled. Shared Nginx service was not stopped.'
}

case "$ACTION" in
    --preflight) preflight;;
    --install) install_pond;;
    --rollback) rollback_release;;
    --enable-https) enable_https;;
    --disable-https) disable_https;;
    --status)
        systemctl is-active respawn-pond-php-fpm.service || true
        php "$APP_ROOT/current/tools/preflight.php" --role=pond --state="$STATE_ROOT/state" --key="$CONFIG_ROOT/master.key" || true
        runuser -u "$SERVICE_USER" -- php "$APP_ROOT/current/pond/bin/pond.php" status "$STATE_ROOT/state" "$CONFIG_ROOT/master.key";;
    *) printf '%s\n' 'Usage: install.sh --preflight | --install --hostname HOST --tls-cert FILE --tls-key FILE | --rollback --release-version VERSION | --enable-https | --disable-https | --status' >&2; exit 2;;
esac