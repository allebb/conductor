#!/usr/bin/env bash

#############################################################
# Conductor Automated Backup Script                         #
# https://github.com/allebb/conductor                       #
# Created by: Bobby Allen (ballen@bobbyallen.me) 20/01/2014 #
#############################################################

# Number of days to retain backups for (after this they will be deleted!).
DAYS="${BACKUP_RETENTION_DAYS:-7}"

# Environment overrides make alternate installations and isolated testing
# possible; normal installations use the defaults below.
CONDUCTOR_CONFIG="${CONDUCTOR_CONFIG:-/etc/conductor.conf}"
CONDUCTORBIN="${CONDUCTOR_BIN:-$(command -v conductor || true)}"
PHPBIN="${CONDUCTOR_PHP_BIN:-$(command -v php || true)}"
CONAPPSDIR="${CONDUCTOR_APPS_DIR:-/var/conductor/applications}"
BACKUPDIR="${CONDUCTOR_BACKUP_DIR:-/var/conductor/backups}"

if [ -z "$CONDUCTORBIN" ] || [ ! -x "$CONDUCTORBIN" ]; then
    echo "The conductor binary could not be found or is not executable." >&2
    exit 1
fi
if [ -z "$PHPBIN" ] || [ ! -x "$PHPBIN" ]; then
    echo "The PHP binary could not be found or is not executable." >&2
    exit 1
fi
if [ ! -f "$CONDUCTOR_CONFIG" ]; then
    echo "The Conductor configuration file could not be found: ${CONDUCTOR_CONFIG}" >&2
    exit 1
fi
if [ ! -d "$CONAPPSDIR" ]; then
    echo "The Conductor applications directory could not be found: ${CONAPPSDIR}" >&2
    exit 1
fi
if [ ! -d "$BACKUPDIR" ]; then
    echo "The Conductor backup directory could not be found: ${BACKUPDIR}" >&2
    exit 1
fi
if ! [[ "$DAYS" =~ ^[0-9]+$ ]]; then
    echo "Backup retention days must be a non-negative whole number." >&2
    exit 1
fi

# Read exact application names from the JSON configuration. Invalid JSON or an
# invalid exclusion value aborts the run so a MUST-NOT-BACK-UP entry can never
# be silently ignored.
if ! EXCLUDED_APPLICATION_OUTPUT=$("$PHPBIN" -r '
    $path = $argv[1];
    try {
        $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        fwrite(STDERR, "Unable to parse " . $path . ": " . $error->getMessage() . PHP_EOL);
        exit(1);
    }
    $excluded = $config["scheduled-backups"]["exclude-applications"] ?? [];
    if (!is_array($excluded)) {
        fwrite(STDERR, "scheduled-backups.exclude-applications must be a JSON array." . PHP_EOL);
        exit(1);
    }
    foreach ($excluded as $application) {
        if (!is_string($application) || $application === "" || preg_match("/[\\r\\n]/", $application)) {
            fwrite(STDERR, "Each backup exclusion must be a non-empty application name." . PHP_EOL);
            exit(1);
        }
        echo $application, PHP_EOL;
    }
' "$CONDUCTOR_CONFIG"); then
    exit 1
fi

declare -A EXCLUDED_APPLICATIONS=()
while IFS= read -r application; do
    [ -n "$application" ] && EXCLUDED_APPLICATIONS["$application"]=1
done <<< "$EXCLUDED_APPLICATION_OUTPUT"

backup_failed=0
while IFS= read -r -d '' application_path; do
    appname=$(basename "$application_path")
    if [ -n "${EXCLUDED_APPLICATIONS[$appname]+configured}" ]; then
        echo "Skipping '${appname}' as per ${CONDUCTOR_CONFIG}."
        continue
    fi

    echo "Backing up '${appname}' application..."
    if ! "$CONDUCTORBIN" backup "$appname"; then
        echo "Backup failed for '${appname}'." >&2
        backup_failed=1
    fi
done < <(find "$CONAPPSDIR" -mindepth 1 -maxdepth 1 -type d -print0 | sort -z)

# Remove regular backup files older than the configured retention period.
find "$BACKUPDIR" -mindepth 1 -maxdepth 1 -type f -mtime "+${DAYS}" -delete

exit "$backup_failed"
