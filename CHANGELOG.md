# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project aims to follow Semantic Versioning.

## [Unreleased]

### Added
- Added `CHANGELOG.md` to track notable changes over time.
- Added pagination support (`page`/`per_page`) for the `metrics/routes-volume` endpoint (with total count metadata).
- Added pagination support (`page`/`per_page`) for the `metrics/routes` endpoint (with total count metadata).
- Added Pest coverage for the install command, partition rolling command, slow transaction monitor logging flow, and dashboard authorization.
- Added `metrics/queries-volume` and `metrics/queries-duration` endpoints for chart-specific transaction metrics.

### Fixed
- Fixed metrics aggregation queries to group by route fields and avoid MySQL-only `ANY_VALUE` usage for broader database support.
- Fixed `metrics/routes-volume` pagination to clamp `per_page` to a sane numeric range.
- Stored the retry toggle marker under the application's storage path (configurable via `state_path`) to avoid unwritable vendor paths.
- Fixed the transactions routes table pagination resetting back to page 1 when the page size changed.
- Throws `AuthenticationException` instead of 403 when an unauthenticated user attempts to access the dashboard, allowing for proper redirection to login.
- Safely handle null user in dashboard gate check.
