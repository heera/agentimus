#!/usr/bin/env bash
# Install the WordPress PHPUnit test library + a WP core checkout so the integration
# suite (tests/integration) can boot the plugin inside real WordPress against a real
# database. Standard wp-cli scaffold script; safe to re-run.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
#
# Uses a DEDICATED test database (default name below) — never point it at a live DB;
# it is dropped and recreated on install.

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
	if [ "$(which curl)" ]; then
		curl -s "$1" > "$2";
	elif [ "$(which wget)" ]; then
		wget -nv -O "$2" "$1"
	else
		echo "Error: neither curl nor wget is available." >&2
		exit 1
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# Resolve "latest".
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | sed 's/"version":"//' | head -1)
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found" >&2
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi
set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return;
	fi
	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR/wordpress-trunk"
		rm -rf "$TMPDIR/wordpress-trunk/*"
		svn export --quiet https://core.svn.wordpress.org/trunk "$TMPDIR/wordpress-trunk/wordpress"
		mv "$TMPDIR/wordpress-trunk/wordpress/*" "$WP_CORE_DIR"
	else
		if [ "$WP_VERSION" == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			download https://wordpress.org/wordpress-${WP_VERSION}.tar.gz "$TMPDIR/wordpress.tar.gz"
			if tar -tzf "$TMPDIR/wordpress.tar.gz" >/dev/null 2>&1; then
				local ARCHIVE_NAME="wordpress-$WP_VERSION"
			else
				local ARCHIVE_NAME="wordpress-${WP_VERSION}-new-bundled"
			fi
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download https://wordpress.org/${ARCHIVE_NAME}.tar.gz  "$TMPDIR/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	# portable in-place sed argument (Linux vs BSD/macOS)
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		rm -rf "$WP_TESTS_DIR/{includes,data}"
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ "$WP_TESTS_DIR/data"
	fi

	if [ ! -f wp-tests-config.php ]; then
		download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi
}

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]; then
		mysqladmin drop "$DB_NAME" -f --user="$DB_USER" --password="$DB_PASS"$EXTRA
		create_db
		echo "Recreated the database ($DB_NAME)."
	else
		echo "Leaving the existing database ($DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return 0
	fi

	# Parse DB_HOST for a socket or a port.
	local PARTS
	IFS=':' read -ra PARTS <<< "$DB_HOST"
	local DB_HOSTNAME=${PARTS[0]};
	local DB_SOCK_OR_PORT=${PARTS[1]};
	local EXTRA=""

	if ! [ -z "$DB_HOSTNAME" ] ; then
		if [ "$(echo "$DB_SOCK_OR_PORT" | grep -e '^[0-9]\{1,\}$')" ]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif ! [ -z "$DB_SOCK_OR_PORT" ] ; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif ! [ -z "$DB_HOSTNAME" ] ; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	if [ $(mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA --execute='show databases;' | grep "^$DB_NAME$") ]
	then
		echo "Reinstalling will delete the existing test database ($DB_NAME)"
		read -p 'Are you sure you want to proceed? [y/N]: ' DELETE_EXISTING_DB
		recreate_db "$DELETE_EXISTING_DB"
	else
		create_db
	fi
}

install_wp
install_test_suite
install_db
