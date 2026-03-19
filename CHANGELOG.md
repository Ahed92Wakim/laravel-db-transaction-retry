# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project aims to follow Semantic Versioning.

## [Unreleased]

### Added
- Added `CHANGELOG.md` to track notable changes over time.
- Added request-level query logging to `db_request_logs`, with per-query entries stored in `db_query_logs`.
- Added dashboard requests view with HTTP and command tabs powered by the new request logs.
- Added hourly MySQL partitions for `db_request_logs`.
- Added pagination support (`page`/`per_page`) for the `metrics/routes-volume` endpoint (with total count metadata).
- Added pagination support (`page`/`per_page`) for the `metrics/routes` endpoint (with total count metadata).
- Added a route detail dashboard view and made route tables link to per-route insights.
- Added optional `method`, `route_name`, and `url` filters for request metrics/duration/requests endpoints.
- Added Pest coverage for the install command, partition rolling command, slow transaction monitor logging flow, and dashboard authorization.
- Added `metrics/queries-volume` and `metrics/queries-duration` endpoints for chart-specific transaction metrics.
- Added Eloquent models for the package tables with attribute casts, and routed dashboard queries through those models.
- Added dedicated `JsonResource` classes for each dashboard API response so endpoint payloads are normalized consistently.

### Removed
- Removed file- and channel-based retry logging configuration so retry events are now persisted only to the database.
- Removed slow transaction channel logging so slow transaction monitoring now writes only to the package tables.

### Fixed
- Fixed metrics aggregation queries to group by route fields and avoid MySQL-only `ANY_VALUE` usage for broader database support.
- Fixed `metrics/routes-volume` pagination to clamp `per_page` to a sane numeric range.
- Stored the retry toggle marker under the application's storage path (configurable via `state_path`) to avoid unwritable vendor paths.
- Fixed the transactions routes table pagination resetting back to page 1 when the page size changed.
- Throws `AuthenticationException` instead of 403 when an unauthenticated user attempts to access the dashboard, allowing for proper redirection to login.
- Safely handle null user in dashboard gate check.
- Request logging now skips package dashboard/API requests and package artisan commands.
- Fixed query exception persistence to write/read `db_exceptions.sql_query` consistently so exception rows are stored and displayed correctly.
- Fixed dashboard date/time rendering to consistently use the client browser timezone for charts, tooltips, and detail tables.
- Fixed API datetime serialization so response timestamps are returned in ISO-8601 timezone-aware format.
- Fixed request and command chart bucket selection so their x-axis granularity now matches the transactions charts for `1H`, `24H`, `7D`, `14D`, and `30D`.
