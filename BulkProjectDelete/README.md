# BulkProjectDelete

A Kanboard plugin that lets administrators select multiple projects from the project list and delete them all in one action, with no orphaned rows or files left behind.

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4

## Installation

Copy or symlink the `BulkProjectDelete/` directory into your Kanboard `plugins/` directory and reload the application.

## Usage

From **Settings → Projects**, select the checkboxes next to the projects you want to remove, then click **Delete Selected**. You will be shown an impact summary before the destructive action is confirmed.

> **Admin only.** Only application administrators can perform bulk deletions.

## License

MIT — see [LICENSE](LICENSE).
