# DependencyPlugin

Task dependencies for Kanboard, built on top of core task links.

This is currently a skeleton (metadata + empty `initialize()`). Planned scope:

- **Blocked/blocker badges** on the board view, calendar view, and individual task pages, showing when a task is blocked by (or is blocking) another task.
- **Cycle guard**: prevent creating a dependency link that would introduce a circular blocking chain.
- Built entirely on Kanboard's existing core task links feature — no new storage model for the link relationship itself, just presentation and validation on top of it.

See `CHANGELOG.md` for release history.
