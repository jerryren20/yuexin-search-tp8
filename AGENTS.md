# Repository Guidelines

## Project Structure & Module Organization

This is a PHP 8.3+ ThinkPHP 8.1 application. Business code lives in `app/`, organized into `admin`, `api`, and `index` modules, with shared models in `app/model/`, reusable services in `app/service/`, and validation in `app/validate/`. HTTP routes are in `route/`; framework and application settings are in `config/`. Network-drive integrations and other extensions belong in `extend/`. Frontend themes, templates, and static assets are under `public/views/` and `public/static/`; deployment notes and SQL upgrades are in `docs/`. Treat `vendor/`, `runtime/`, uploads, and `data/pan_tree_cache/` as generated or runtime content.

## Build, Test, and Development Commands

Run commands from the repository root with PHP 8.3+ and Composer installed:

```bash
composer install --no-dev --optimize-autoloader
composer dump-autoload
php think run
```

The first command installs the locked dependencies for deployment; the second rebuilds the PSR-4 autoloader and triggers ThinkPHP service discovery; `php think run` starts ThinkPHP’s local server. There is no separate frontend build step or project-level Composer test script. Initialize local settings from `.env.example`; database configuration is maintained in `config/database.php` or by the installer.

## Coding Style & Naming Conventions

Use four-space indentation, UTF-8 source files, one class per file, and the existing `app\\...` namespace layout. Keep class/file names in PascalCase and methods in camelCase; follow surrounding code for framework conventions and Chinese user-facing messages. Keep controllers thin by placing reusable search, cache, transfer, and integration logic in `app/service/` or `extend/`. No repository formatter or linter is configured; use `php -l path/to/file.php` for a syntax check. Do not edit generated dependency files under `vendor/`.

## Testing Guidelines

No committed application test suite or coverage threshold is currently defined. For every change, run `php -l` on modified PHP files and exercise the affected HTTP or installer flow locally. For new tests, keep them outside `vendor/`, use descriptive names such as `SearchResourceValidatorTest.php`, and document the chosen runner in the pull request.

## Commit & Pull Request Guidelines

Recent history uses short, imperative Conventional Commit-style subjects such as `fix: repair installer SQL import` and `feat: migrate ...`. Use a focused `feat:`, `fix:`, `refactor:`, or `docs:` prefix and keep unrelated changes separate. Pull requests should explain behavior and configuration/database impacts, list validation commands, link the relevant issue when one exists, and include screenshots or short recordings for frontend/admin changes. Never commit `.env`, credentials, access tokens, logs, install locks, or runtime uploads.
