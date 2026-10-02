# Changelog

All notable changes to this project are documented in this file. The project
follows [semantic versioning](https://semver.org/).

## [0.3.0] - 2026-10-02

### Added

* Findings. The report dialog and the report detail group the diagnostics below the reported object: website address, placement, visibility, files and permissions, a few notices per group with a pointer to the technical details, and one note that these are stored settings and permission facts, not a check of the website. The Markdown version lists all findings, and the new email marker `{context.findings}` (also in the default email template) contains them as text.
* Website address facts (`context.routing`): why a page or the page of a record has no website address or which settings explain it: no site, a page type TYPO3 offers no view for, a language the site does not have or that is disabled, a missing page translation with the fallback languages, a page that is new or deleted in the workspace, a site base without host, an address TYPO3 could not build.
* Placement (`context.placement`): the column of a content element and whether the backend layout of its page has it (as TYPO3 offers the columns in the editing form, including columns of extensions), the backend layout and the parent page it is set on, and "Show content from page" in both directions.
* Translation behaviour of pages ("Hide default language of page", "Hide page if no translation for current language exists", including `hidePagesIfNotTranslatedByDefault`) in `context.visibility`.
* File checks name references to files of a type the file field does not allow (`typeNotAllowed`), which TYPO3 removes when the record is saved. The type is the extension of the file name, checked like TYPO3 does it; image fields allow the image file types of TYPO3, by default including PDF, AI and SVG.
* File usage (`context.fileUsage`): for a reported file, the records and fields that refer to it.
* Permission facts (`context.permissions`) of editors: table permission, page permissions, edit locks, language, record type, page type, fields that are not available (exclude fields, fields of the default language in translations, fields disabled in TSconfig), and for files and folders the file permissions, read-only file mounts and whether the storage is writable. They are inputs of TYPO3's checks; the report never says whether something can be edited.
* Creation time and last change of the reported page or record, and the time zone of the server. Scheduled and expired notices name the server time zone when the reporter's browser was in another one. The report detail shows the column and the last change of a record.

### Changed

* The visibility notices and file check notices are now part of the findings.

### Fixed

* The frontend URL follows the "View" button of TYPO3: external links, shortcuts, mount points and custom page types get their address, and pages TSconfig `TCEMAIN.preview.disableButtonForDokType` excludes from viewing do not. A page deleted in the workspace gets no address; the reason is in `context.routing`.
* The table of the record list is only reported for tables of the TCA the reporter may list.
* The report item of the context menu never breaks the context menu of files, folders, pages and records: when a file, folder or storage cannot be resolved or checked (e.g. a file deleted after the file list was loaded, or a storage driver that fails), the item is left out. TYPO3 13.4 and 14.3 themselves still fail on the context menu of a file or folder they cannot find any more ("Call to a member function checkActionPermission() on null"), also without Context Reporter; reload the file list.

### Security

* The new facts stay within the reporter's access: translations and fallback languages only in languages the reporter may use, the backend layout only with permission for both backend layout fields and through accessible parent pages, pages and records only named when accessible (otherwise counted), records of users and groups never named, and only the reporter's own permissions without owners, groups or other users.

### Compatibility

* The report format stays `context-reporter.report.v1`: all new sections and fields are optional, reports created before have none and are shown as before. Lists of codes (e.g. `routing.notes`, file `problems`) can grow; receivers should ignore unknown values. The database schema is unchanged. Flush the caches after updating.
* TYPO3 13.4 LTS and TYPO3 14.3 LTS, PHP 8.2, 8.3, 8.4 and 8.5. On TYPO3 13.4.0 to 13.4.14, which have no `PreviewUriBuilder::isPreviewable()`, the same TSconfig rule is applied.

## [0.2.0] - 2026-10-01

### Added

* Visibility diagnostics. For a reported page or record, the report lists the stored TYPO3 settings that keep it from website visitors: hidden, a start date in the future or an end date in the past, frontend user groups (including "Hide at login" and "Show at any login") and "Hidden in menus". The same settings are listed for the page of a record, for parent pages that pass restrictions on with "Extend to subpages", and for translations, together with missing translations and site languages that are disabled. In a workspace, the report says whether the object is new, changed or deleted there. The data is in the new section `context.visibility`.
* File checks. A reported file is checked for being marked as missing, not found in its storage, empty (0 bytes) or in a storage that is offline in the backend; only a reported file is looked up in its storage, and not while the storage is offline. For a reported page or record, the file references in the file fields its type shows are checked with the file index: hidden references, references to files that no longer exist, and files that are marked as missing, empty or in an offline storage. A report from the metadata of a file (Edit metadata) checks that file, and a reported file reference is checked like the references of a record. The data is in the new section `context.fileChecks`.
* Short notices about both, in English and German, below the reported object in the report dialog and the report detail. They are only shown when there is something to point out. The technical details, the email body (`{context.details}`) and the Markdown export describe all collected facts; the JSON download and the webhook payload contain the sections as they are.

### Security

* The diagnostics stay within the reporter's access: parent pages only up to the first page the reporter cannot access, "Extend to subpages" only with permission for that field, translations only in the languages the reporter may use, only the reporter's current workspace, and file references only with permission to list file references and to edit the field. Files the reporter may not access are counted as not checked, without name, UID or storage.
* A reported file reference whose file the reporter may not access no longer carries the name of that file as its label or in the summary.

### Compatibility

* The diagnostics describe stored TYPO3 settings and the file index, not the rendered website. Templates, TypoScript, caches, extensions, the visitor's login and image processing can still change what visitors see; see the limitations in the documentation.
* The report format stays `context-reporter.report.v1`: `visibility` and `fileChecks` are new optional sections, and reports created before 0.2.0 have neither. The database schema is unchanged. Flush the caches after updating.
* TYPO3 13.4 LTS and TYPO3 14.3 LTS, PHP 8.2, 8.3, 8.4 and 8.5. Tested with MariaDB 10.11, MySQL 8.0 and SQLite; PostgreSQL has not been tested.

## [0.1.2] - 2026-09-30

### Fixed

* Reports about a page use the language the reporter is looking at. On TYPO3 14.3, reports from the Page module always named the default language; on both TYPO3 versions, the language selected in the Preview module and in page modules of other extensions, such as the Visual Editor, was ignored. The frontend URL of the report now points to the selected translation.
* Reports about a page translation, for example from its editing form, name the language of the translation instead of the default language.
* A selected language is only used when the page is translated into it and the reporter may use it; otherwise the report names the default language, as the Page module shows it. When several translations are shown side by side, the report uses the default language, like the Preview module of TYPO3 14.

### Added

* A ready-to-import n8n workflow (`Documentation/Integration/N8n`) that verifies the webhook signature, creates a GitLab issue, returns its reference to the reporter and does not create a second issue when a delivery is repeated. The documentation also describes the setup for Jira.
* Documentation on reporting frontend problems from the Page and Preview module.

### Compatibility

* PHP 8.5 is supported, on TYPO3 13.4 LTS and TYPO3 14.3 LTS.
* Classic mode (the ZIP file from the TYPO3 Extension Repository, installed in the Extension Manager) has been verified on TYPO3 13.4 and TYPO3 14.3. Switch off the automatic installation of the Extension Manager before uploading the ZIP file; see the installation guide.
* PostgreSQL has not been tested.

## [0.1.1] - 2026-09-23

Metadata release. Code, database schema, configuration and the report format
are unchanged.

### Changed

* The extension title is now "Context Reporter", without a subtitle.
* The extension state is now `stable`, based on the validation of 0.1.0 on TYPO3 13.4 LTS and TYPO3 14.3. The known limitations are unchanged: classic mode (Extension Manager) has not been verified and PostgreSQL has not been tested.
* The Composer package description no longer uses the former subtitle.

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
