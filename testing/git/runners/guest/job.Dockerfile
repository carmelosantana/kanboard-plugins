# The image every isolation-option job runs in (runner label docker://kb-ci-job:1), built in the
# VM while provisioning. Pinned base digest and package versions; a workflow cannot pull another
# image at run time (the VM has no egress beyond Traefik and the allowlist proxy).
FROM debian:trixie-20261027-slim@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4
RUN apt-get update -qq \
 && apt-get install -y -qq --no-install-recommends curl=8.14.1-2+deb13u7 ca-certificates=20250419+deb13u1 \
 && rm -rf /var/lib/apt/lists/*
