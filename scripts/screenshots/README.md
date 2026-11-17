# Directory screenshots

`capture.mjs` takes the screenshots that the [ModMenu directory](https://github.com/carmelosantana/kanboard-modmenu-directory)
lists for each plugin. It runs against its own throwaway Kanboard on `:8082`, never the `:8081` dev stack, because
`:8081` holds an import of a real board. Every image shows made-up demo data only.

| File | What it does |
|---|---|
| `docker-compose.shots.yml` | Clean Kanboard on `http://localhost:8082` (admin/admin) with a fresh volume and every listed plugin mounted read-only |
| `docker-compose.shots-shadcn.yml` | Adds ShadcnTheme (run the ShadcnTheme shots with it, everything else without) |
| `seed.mjs` | Seeds the "Website Launch" demo project, users, tasks, subtasks, comments, links, time blocks through JSON-RPC |
| `fake-ai.mjs` | Canned, offline OpenAI-compatible endpoint, so AI features render without a key |
| `capture.mjs` | Seeds, sets up plugin state through the UI, captures, writes `<out>/<kebab-name>/<n>.webp` |

Output: 16:10 frames from a 1280x800 viewport at device scale 1, WebP quality 80, metadata stripped. The
script prints each file's size and flags any over 120 KB. Plugins not on this stack (BattleLobby, RpcGuard)
are skipped. ModMenuUpdater is skipped too: with a current ModMenu its one page only says it is not needed.

## Run

Playwright is not a dependency of this repo. Point `NODE_PATH` at an existing install (the script loads it
with `require`, which honours `NODE_PATH`).

```sh
export NODE_PATH=~/.claude/render-harness/node_modules
DIR=../kanboard-modmenu-directory

docker compose -f scripts/screenshots/docker-compose.shots.yml up -d
node scripts/screenshots/capture.mjs --out $DIR/assets                      # every plugin, stock theme

docker compose -f scripts/screenshots/docker-compose.shots.yml -f scripts/screenshots/docker-compose.shots-shadcn.yml up -d
node scripts/screenshots/capture.mjs --out $DIR/assets --only ShadcnTheme

# After plugins.json lists the new images: ModMenu's Browse tab, reading the local directory checkout.
docker compose -f scripts/screenshots/docker-compose.shots.yml up -d
node scripts/screenshots/capture.mjs --out $DIR/assets --only ModMenu --directory $DIR

docker compose -f scripts/screenshots/docker-compose.shots.yml down -v      # throw the stack away
```

`--only` takes plugin names or folder names. `--keep-png` keeps the source PNGs next to the WebP files for
review. Check every image before you commit it.
