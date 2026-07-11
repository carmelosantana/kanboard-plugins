#!/bin/bash
# Dev-harness entrypoint override for kb-suite.
#
# Identical to the upstream kanboard/kanboard entrypoint EXCEPT it does not
# `chown -R nginx:nginx /var/www/app/plugins`. The plugin directories are
# bind-mounted host git repos (repo-per-plugin split, 2026-07-10); the upstream
# chown rewrites them to the in-container nginx uid (100) on every start, which
# breaks host-side git ops until `sudo chown` is run.
#
# nginx (uid 100) serves the plugins via their world-readable bits, so it never
# needs to *own* them. The data dir chown is kept — the SQLite `kb-suite-data`
# named volume must be writable by nginx.
#
# Keep this in sync with upstream if the base image's entrypoint ever changes:
#   docker run --rm --entrypoint cat kanboard/kanboard:latest /usr/local/bin/entrypoint.sh

# Generate a new self signed SSL certificate when none is provided in the volume
if [ ! -f /etc/nginx/ssl/kanboard.key  ] || [ ! -f /etc/nginx/ssl/kanboard.crt ]
then
    openssl req -x509 -nodes -newkey rsa:2048 -keyout /etc/nginx/ssl/kanboard.key -out /etc/nginx/ssl/kanboard.crt -subj "/C=GB/ST=London/L=London/O=Self Signed/OU=IT Department/CN=kanboard.org"
fi

chown -R nginx:nginx /var/www/app/data
# NOTE: intentionally NOT chowning /var/www/app/plugins (bind-mounted host repos)

exec /usr/bin/s6-svscan /etc/services.d
