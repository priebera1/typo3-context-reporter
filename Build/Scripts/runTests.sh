#!/usr/bin/env bash
#
# Runs the extension's quality checks in a disposable container based on the
# official TYPO3 core testing images, so the host PHP version does not matter.
#
# Usage: Build/Scripts/runTests.sh [-t <13|14>] [-p <8.2|8.3|8.4>] [-s <suite>] [-d <dbms>] [-i <version>] [-- <extra arguments>]
#
# Suites:
#   composer    composer install/update for the selected TYPO3 branch, or any
#               composer command given after --
#   unit        PHPUnit unit tests
#   functional  PHPUnit functional tests
#   stan        PHPStan
#   cs          php-cs-fixer dry run
#   csfix       php-cs-fixer, applying fixes
#   lint        php -l on all PHP files
#   xlf         XLIFF well-formedness and translation key parity
#
# TYPO3 branch (-t):
#   13          TYPO3 13.4 LTS (default)
#   14          TYPO3 14.3
#   The branch only matters for the composer suite: it resolves the tree to
#   that branch. Every other suite runs against whatever is in .Build/vendor.
#
# Functional test database (-d):
#   sqlite      default, no database server
#   mariadb     disposable MariaDB container, version with -i (default 10.11)
#   mysql       disposable MySQL container, version with -i (default 8.0)
#
set -euo pipefail

PHP_VERSION="8.2"
SUITE="unit"
DBMS="sqlite"
DBMS_VERSION=""
TYPO3_BRANCH="13"

while getopts ":p:s:d:i:t:h" option; do
    case "${option}" in
        p) PHP_VERSION="${OPTARG}" ;;
        s) SUITE="${OPTARG}" ;;
        d) DBMS="${OPTARG}" ;;
        i) DBMS_VERSION="${OPTARG}" ;;
        t) TYPO3_BRANCH="${OPTARG}" ;;
        h)
            sed -n '2,33p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option -${OPTARG}" >&2
            exit 1
            ;;
    esac
done
shift $((OPTIND - 1))
if [[ "${1:-}" == "--" ]]; then
    shift
fi

case "${PHP_VERSION}" in
    8.2 | 8.3 | 8.4) ;;
    *)
        echo "Unsupported PHP version ${PHP_VERSION}. Use 8.2, 8.3 or 8.4." >&2
        exit 1
        ;;
esac

case "${TYPO3_BRANCH}" in
    13) TYPO3_CONSTRAINT="^13.4" ;;
    14) TYPO3_CONSTRAINT="^14.3" ;;
    *)
        echo "Unsupported TYPO3 branch ${TYPO3_BRANCH}. Use 13 or 14." >&2
        exit 1
        ;;
esac

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
IMAGE="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
CACHE_DIR="${ROOT_DIR}/.cache"
mkdir -p "${CACHE_DIR}/composer"
DOCKER_OPTIONS=()

run() {
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --volume "${ROOT_DIR}:${ROOT_DIR}" \
        --workdir "${ROOT_DIR}" \
        --env COMPOSER_CACHE_DIR="${CACHE_DIR}/composer" \
        --env COMPOSER_HOME="${CACHE_DIR}/composer-home" \
        --env XDEBUG_MODE=off \
        --env TYPO3_CONTEXT=Testing \
        ${DOCKER_OPTIONS[@]+"${DOCKER_OPTIONS[@]}"} \
        "${IMAGE}" "$@"
}

# Starts a disposable database server for the functional tests and removes it on exit
start_database() {
    local image ping
    case "${DBMS}" in
        mariadb)
            image="mariadb:${DBMS_VERSION:-10.11}"
            ping="mariadb-admin ping -h 127.0.0.1 -uroot -pfunctional --silent"
            ;;
        mysql)
            image="mysql:${DBMS_VERSION:-8.0}"
            ping="mysqladmin ping -h 127.0.0.1 -uroot -pfunctional --silent"
            ;;
        *)
            echo "Unsupported database ${DBMS}. Use sqlite, mariadb or mysql." >&2
            exit 1
            ;;
    esac
    local suffix
    suffix="$(date +%s)-$$"
    NETWORK="context-reporter-tests-${suffix}"
    DATABASE_CONTAINER="context-reporter-db-${suffix}"
    trap 'docker rm -f "${DATABASE_CONTAINER}" > /dev/null 2>&1 || true; docker network rm "${NETWORK}" > /dev/null 2>&1 || true' EXIT
    docker network create "${NETWORK}" > /dev/null
    docker run --detach --rm \
        --name "${DATABASE_CONTAINER}" \
        --network "${NETWORK}" \
        --env MARIADB_ROOT_PASSWORD=functional \
        --env MYSQL_ROOT_PASSWORD=functional \
        --tmpfs /var/lib/mysql \
        "${image}" > /dev/null
    for _ in $(seq 1 60); do
        if docker exec "${DATABASE_CONTAINER}" sh -c "${ping}" > /dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    # The server restarts once after the initialisation; wait until it accepts connections again
    sleep 3
    if ! docker exec "${DATABASE_CONTAINER}" sh -c "${ping}" > /dev/null 2>&1; then
        echo "The ${DBMS} server did not start." >&2
        exit 1
    fi
    DOCKER_OPTIONS=(
        --network "${NETWORK}"
        --env typo3DatabaseDriver=mysqli
        --env typo3DatabaseHost="${DATABASE_CONTAINER}"
        --env typo3DatabasePort=3306
        --env typo3DatabaseName=func_test
        --env typo3DatabaseUsername=root
        --env typo3DatabasePassword=functional
    )
}

case "${SUITE}" in
    composer)
        if [[ $# -eq 0 ]]; then
            # Resolve the tree to the requested TYPO3 branch. The extension supports
            # both, so only a temporary constraint decides which one is installed.
            set -- update --no-progress --no-interaction \
                --with "typo3/cms-core:${TYPO3_CONSTRAINT}" \
                --with-all-dependencies
            # Switching the branch also switches the major version of the
            # typo3/class-alias-loader Composer plugin. Composer has the old plugin
            # loaded while it writes the new one, so the autoload dump of that first
            # run fails; the second run uses the plugin that is now on disk.
            if ! run composer "$@"; then
                echo "Repeating the update with the Composer plugins of the new TYPO3 branch." >&2
                run composer "$@"
            fi
            exit $?
        fi
        run composer "$@"
        ;;
    unit)
        run php .Build/bin/phpunit -c phpunit.unit.xml "$@"
        ;;
    functional)
        if [[ "${DBMS}" != "sqlite" ]]; then
            start_database
        fi
        run php .Build/bin/phpunit -c phpunit.xml "$@"
        ;;
    stan)
        run php -d memory_limit=1G .Build/bin/phpstan analyse -c Build/phpstan.neon --no-progress "$@"
        ;;
    cs)
        run php .Build/bin/php-cs-fixer fix --config=Build/php-cs-fixer.php --dry-run --diff "$@"
        ;;
    csfix)
        run php .Build/bin/php-cs-fixer fix --config=Build/php-cs-fixer.php "$@"
        ;;
    lint)
        run sh -c 'find Classes Configuration Tests ext_emconf.php -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null && echo "No syntax errors."'
        ;;
    xlf)
        run php Build/Scripts/validateXlf.php "$@"
        ;;
    *)
        echo "Unknown suite ${SUITE}" >&2
        exit 1
        ;;
esac
