# Kanboard Plugin Suite

Four independently-installable Kanboard v1.2.47 plugins, built buildless (plain JS/CSS + jQuery + global `KB`), targeting PHP >= 8.4.

| Plugin | Goal |
|--------|------|
| **BulkProjectDelete** | Multi-select + delete projects from the admin list, zero orphans |
| **ShadcnTheme** | shadcn/ui dark-first theme; task-color fix; login restyle; favicon/logo upload |
| **FeatureSync** | Bulk-copy actions/tags/columns from a source project to many targets |
| **SubtaskGenerator** | "Generate subtasks" via LLM (php-agents; default local Ollama) |

## Testing

One Docker stack mounts all four plugins:

```
cd testing
docker compose -f docker-compose.dev.yml up -d   # http://localhost:8081  (admin/admin)
```

See `testing/` for seed + snapshot + verify scripts.

Plans live in `../plugin-plans/` and `../plans/`.
