# Changelog

All notable changes to this project are documented in this file. The project
follows [semantic versioning](https://semver.org/).

## [0.1.0] - 2026-09-18

First public release.

### Added

* Report a problem where it happens: from the backend toolbar, the context menu of pages, records, files and folders, the record list, the Page module, the file list and the record editing form.
* Object-aware TYPO3 context, attached automatically: page, record and content type, editing form, file, folder and storage, site, language, workspace, backend module, system and browser. Only allowlisted metadata is collected, and the dialog shows everything before it is sent.
* Screenshots from screen capture, upload or the clipboard, annotated in the browser with rectangle, arrow, freehand drawing, text and opaque redaction, and flattened before upload.
* Delivery by email through the TYPO3 mail API (markers, custom plain-text template, JSON and screenshot attachments) and by a signed webhook (HMAC-SHA256, optional authentication header, secrets from environment variables, ticket references), with status panels and test deliveries.
* Downloads as JSON (schema `context-reporter.report.v1`) and Markdown.
* A local report history in System › Context Reports: filters, a report detail with the reported object, screenshot, description and delivery history, manual retry, deletion and an open/resolved state.
* Copy (summary, Markdown, JSON, link) and Download actions, in the report detail and right after sending.
* Settings for administrators (general, privacy, reports & storage, email, webhook); values in the TYPO3 system configuration take precedence.
* Privacy controls for the reporter identity, browser details and the opt-in recent backend errors.
* Retention: keep forever (default) or a number of days, with storage statistics, a cleanup preview and the `context-reporter:cleanup` command.
* User TSconfig to disable reporting, and a rate limit per user.
* English and German backend labels.

### Compatibility

* TYPO3 13.4 LTS and TYPO3 14.3, with the same package.
* PHP 8.2, 8.3 and 8.4.
* Tested with MariaDB 10.11, MySQL 8.0 and SQLite. PostgreSQL has not been tested.

### Security

* Reports only reference pages, records, files and folders the reporter can access (page and table permissions, file storages, file mounts, file permissions). File metadata and file references also require access to the file, and in workspaces only records of the reporter's current workspace are accepted.
* The context the reporter reviewed is sealed on the server and cannot be changed in the browser before sending.
* Apart from the record label, record field values are not collected; multi-line text is never used as a label.
* Secrets are write-only in the settings. Error messages, ticket references returned by webhook receivers and log entries are sanitized.
* The Markdown export escapes text entered by users.
* Screenshots are validated on the server, and downloads are sent with restrictive headers.
* The email body template must be a `.txt` file below `Resources/Private/` of an extension.
* The report history and the settings are available to administrators only.
