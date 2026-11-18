# Git hosting P1 metrics

## Idle RAM (Task 2, 2026-11-18)

Git stack up, bootstrapped, no repos, no traffic for ~20 s. Host: Linux, 78.5 GiB.
`docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' kb-git-traefik kb-git-forgejo kb-git-db kb-git-broker kb-suite`

```
NAME             CPU %     MEM USAGE / LIMIT
kb-git-traefik   0.00%     18.77MiB / 78.53GiB
kb-git-forgejo   0.10%     163MiB / 78.53GiB
kb-git-db        0.00%     52.89MiB / 78.53GiB
kb-git-broker    0.00%     1.465MiB / 78.53GiB
kb-suite         0.00%     32.47MiB / 78.53GiB
```

Git overlay total: about 236 MiB idle (Forgejo 163, Postgres 53, Traefik 19, broker 1.5).
Authentik is not in the stack yet; the spec's 1.5–2.5 GB estimate includes it.

Images: Forgejo 15.0.11-rootless, Postgres 17.12-alpine3.24, Traefik v3.7.19, broker built
locally (`kanboard-git-broker:dev`, 13.7 MB).

## Hostname note

Host side: `http://git.localhost:8090` (Traefik's only published port, 127.0.0.1). Container side:
the same URL, via Traefik's network alias `git.localhost` and its in-network `:8090` entrypoint.
Docker DNS resolves the alias in containers, but curl/libcurl (and so `git` and PHP's curl
extension) pin `*.localhost` to loopback, so in-container clients need
`--resolve`/`http.curloptResolve` until the hostname question is settled.
