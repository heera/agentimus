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

# ⚠️ -L, because the archives below are served by a redirect: without it this
# wrote the redirect body to disk and the extract failed on a file that looked
# like it had downloaded fine. -f so an HTTP error is an error rather than a
# saved 404 page, -S so it says which one.
download() {
	if [ "$(which curl)" ]; then
		curl -fsSL "$1" > "$2";
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

# The WordPress test library, fetched as ONE tarball from the wordpress-develop
# git mirror instead of exported with an svn client. Same tree, no client to
# install first — and installing that client is what cost a release night on
# 2026-08-19, when `apt-get install subversion` stalled three runners of six.
#
# ⚠️ The mirror normalises every tag to THREE components: svn's `tags/7.1` is
# `7.1.0` here, while `tags/6.8.1` keeps its own name. Branches are named the
# same on both sides. Get this wrong and the fetch 404s on exactly the
# every-other-release versions.
case "$WP_TESTS_TAG" in
	trunk)
		WP_DEVELOP_REF="heads/trunk"
		;;
	branches/*)
		WP_DEVELOP_REF="heads/${WP_TESTS_TAG#branches/}"
		;;
	tags/*)
		WP_DEVELOP_TAG="${WP_TESTS_TAG#tags/}"
		case "$WP_DEVELOP_TAG" in
			*.*.*) ;;
			*) WP_DEVELOP_TAG="$WP_DEVELOP_TAG.0" ;;
		esac
		WP_DEVELOP_REF="tags/$WP_DEVELOP_TAG"
		;;
	*)
		echo "Could not work out which wordpress-develop ref answers '$WP_TESTS_TAG'" >&2
		exit 1
		;;
esac

WP_DEVELOP_DIR=${WP_DEVELOP_DIR-$TMPDIR/wordpress-develop}

# Both callers below want something out of that tree, and neither should pull it
# twice.
#
# ⭐ Diffed against `svn export tags/7.1` before this replaced it: includes/ is
# byte-identical, and data/ differs by ONE entry — data/plugins/hello-dolly, an
# EMPTY directory, which git cannot represent and so no tarball can carry. No
# file is missing. Nothing in tests/integration reads DIR_TESTDATA, so nothing
# here wants it; if a future test ever does, mkdir it rather than going back to
# an svn client.
fetch_wp_develop() {
	if [ -d "$WP_DEVELOP_DIR/tests/phpunit/includes" ]; then
		return
	fi
	rm -rf "$WP_DEVELOP_DIR"
	mkdir -p "$WP_DEVELOP_DIR"
	download "https://github.com/WordPress/wordpress-develop/archive/refs/${WP_DEVELOP_REF}.tar.gz" "$TMPDIR/wordpress-develop.tar.gz"
	# ⛔ The whole tree, not a --wildcards filter naming just the two directories:
	# --wildcards is GNU tar only, and these also run on macOS, where tar is BSD.
	tar --strip-components=1 -zxmf "$TMPDIR/wordpress-develop.tar.gz" -C "$WP_DEVELOP_DIR"
}

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return;
	fi
	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		# The BUILT nightly — the same tree `svn export core.svn/trunk` used to
		# hand back. ⛔ NOT wordpress-develop's src/: that is the unbuilt source,
		# and the suite would boot core with its scripts and styles uncompiled.
		# ⚠️ Nothing in the CI matrix asks for nightly, so this path is not
		# exercised on every push the way the tagged ones are.
		rm -rf "$TMPDIR/wordpress-nightly"
		download https://wordpress.org/nightly-builds/wordpress-latest.zip "$TMPDIR/wordpress-nightly.zip"
		unzip -q -o "$TMPDIR/wordpress-nightly.zip" -d "$TMPDIR/wordpress-nightly"
		# ⭐ The glob sits OUTSIDE the quotes. Quoted, it is a literal asterisk and
		# the move silently copied nothing — the line this replaces had that bug.
		mv "$TMPDIR/wordpress-nightly/wordpress/"* "$WP_CORE_DIR"
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
		fetch_wp_develop
		cp -r "$WP_DEVELOP_DIR/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -r "$WP_DEVELOP_DIR/tests/phpunit/data" "$WP_TESTS_DIR/data"
	fi

	if [ ! -f wp-tests-config.php ]; then
		fetch_wp_develop
		cp "$WP_DEVELOP_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config.php
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
