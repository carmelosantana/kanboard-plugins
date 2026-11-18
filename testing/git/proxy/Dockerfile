# Registry allowlist proxy for CI jobs (Task 16, ruling F15): tinyproxy from Debian 13, pinned base
# image digest and package version. Jobs reach it as kb-proxy:3128 (the runner VMs forward it here);
# it allows CONNECT/GET only to the hosts in allowlist, and answers 403 to everything else.
FROM debian:trixie-20261027-slim@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4
RUN apt-get update -qq \
 && apt-get install -y -qq --no-install-recommends tinyproxy=1.11.2-1 \
 && rm -rf /var/lib/apt/lists/*
COPY tinyproxy.conf allowlist /etc/tinyproxy/
USER tinyproxy
EXPOSE 3128
ENTRYPOINT ["/usr/bin/tinyproxy", "-d", "-c", "/etc/tinyproxy/tinyproxy.conf"]
