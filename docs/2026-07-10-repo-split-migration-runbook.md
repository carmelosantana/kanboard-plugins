# Repo-Split Migration Runbook

- **Date:** 2026-07-10
- **Goal:** Move the 9 suite plugins out of the `carmelosantana/kanboard-plugins` monorepo into
  one GitHub repo per plugin, each with build-on-tag CI, then repoint the ModMenu directory at the
  new release URLs. Demote (not delete) the monorepo to host the shared dev harness + docs.
- **Executed inline, phase-by-phase, by the orchestrator** (mechanical git/gh/CI ops — NOT SDD).

## Critical invariants
1. **Repo slug ≠ Kanboard plugin name.** Only the GitHub repo/URL changes. NEVER rename plugin
   folders, `Kanboard\Plugin\<Name>` namespaces, `plugin.json` `"name"`, directory `"name"` keys,
   or any `requires`/`recommends` reference. `CalendarPlugin` stays `CalendarPlugin` even though its
   repo is `kanboard-calendar`.
2. **Per-repo release convention** (matches HelloHarmozi's existing `v1.0.0`): tag = `vX.Y.Z`
   (bare v-prefix, NOT `<Plugin>-vX.Y.Z`); release name = tag; asset = `<PluginName>-X.Y.Z.zip`;
   zip has a single top-level folder = `<PluginName>` (PascalCase), excluding `.git/ .github/ Test/`.
   `vendor/` (php-agents, AiConnector only) IS included.
3. **Non-destructive ordering:** create → push → release → VERIFY → repoint directory → VERIFY, and
   only THEN demote monorepo / clean up old releases. Phase 6 is gated on explicit sign-off.
4. **Fresh-init snapshot:** each new repo starts with ONE `Initial commit` on `main`.
5. Git identity: `Carmelo Santana <carmelo@vctrs.io>`. Host-side only (never `docker exec`).

## Slug + version map (plugin NAMES are fixed; only repo/URL changes)

| GitHub repo (new)              | Kanboard plugin name | Release |
|--------------------------------|----------------------|---------|
| kanboard-ai-connector          | AiConnector          | v1.0.0 (new)          |
| kanboard-bulk-project-delete   | BulkProjectDelete    | v1.0.1                |
| kanboard-calendar              | CalendarPlugin       | v1.1.0                |
| kanboard-dependency            | DependencyPlugin     | v1.0.0                |
| kanboard-feature-sync          | FeatureSync          | v1.0.1 (overdue bump) |
| kanboard-modmenu               | ModMenu              | v1.1.0                |
| kanboard-scheduler             | SchedulerPlugin      | v1.0.0                |
| kanboard-shadcn-theme          | ShadcnTheme          | v1.0.4 (overdue bump) |
| kanboard-subtask-generator     | SubtaskGenerator     | v1.1.0                |
| kanboard-hello-harmozi (rename)| HelloHarmozi         | v1.0.0 (already live) |

## Phases
- **0. Consolidate:** merge `feat/aiconnector-split` → master (tests green for AiConnector +
  SubtaskGenerator), push, delete branch. Master now = final state of all plugins.
- **1. Create/rename repos:** `gh repo create carmelosantana/<slug> --public --description ...` ×9;
  `gh repo rename kanboard-hello-harmozi --repo carmelosantana/HelloHarmozi`.
- **2. Demote monorepo:** `git rm -r --cached <Plugin>` ×9, add `/<Plugin>/` to `.gitignore`, keep
  `testing/ scripts/ docs/` tracked, README note, commit + push.
- **3. Seed each repo:** add `.github/workflows/release.yml`, repoint homepage/README links to new
  repo, `git init -b main`, remote, `Initial commit`, push `main`.
- **4. Release:** `git tag vX.Y.Z && git push origin vX.Y.Z` → CI builds+publishes; verify each
  asset (200, single `<PluginName>/` folder, no `Test/`, AiConnector carries `vendor/`).
- **5. Repoint directory:** update `../kanboard-modmenu-directory/plugins.json` homepage+download for
  all, bump versions (ShadcnTheme 1.0.4, FeatureSync 1.0.1, SubtaskGenerator 1.1.0), add AiConnector
  entry, add SubtaskGenerator `requires` on AiConnector, repoint HelloHarmozi. Commit + push; verify
  raw URL + all downloads 200.
- **6. Cleanup (GATED, destructive):** after 4+5 verified and sign-off, delete old `<Plugin>-vX.Y.Z`
  releases/tags from the demoted monorepo (`gh release delete <tag> --cleanup-tag`).

## CI workflow (`.github/workflows/release.yml`) — buildless (vendor committed), no third-party actions

```yaml
name: release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build and publish plugin zip
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          NAME=$(grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]+"' plugin.json | head -1 | sed -E 's/.*"([^"]+)"$/\1/')
          VERSION=$(grep -oE '"version"[[:space:]]*:[[:space:]]*"[^"]+"' plugin.json | head -1 | sed -E 's/.*"([^"]+)"$/\1/')
          if [ "v$VERSION" != "$GITHUB_REF_NAME" ]; then echo "tag $GITHUB_REF_NAME != plugin.json v$VERSION"; exit 1; fi
          mkdir -p /tmp/stage/"$NAME"
          rsync -a --exclude '.git' --exclude '.github' --exclude 'Test' --exclude '.DS_Store' ./ /tmp/stage/"$NAME"/
          ( cd /tmp/stage && zip -qr "$GITHUB_WORKSPACE/${NAME}-${VERSION}.zip" "$NAME" )
          gh release create "$GITHUB_REF_NAME" "${NAME}-${VERSION}.zip" --title "$GITHUB_REF_NAME" --notes "Release $GITHUB_REF_NAME"
```

## Follow-ups (non-blocking)
- AiConnector `composer.json` has a local-path php-agents repo; vendor is committed so it ships fine.
  Hygiene: repoint to the public `https://github.com/carmelosantana/php-agents` VCS repo or note
  vendor is committed. Do NOT delete vendor/.
- Per-repo PHPUnit test CI (harness currently lives in the demoted repo) — future.
