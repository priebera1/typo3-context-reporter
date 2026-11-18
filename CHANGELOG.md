# Changelog

All notable changes to this project are documented in this file. The project
follows [semantic versioning](https://semver.org/).

## [0.4.0] - 2026-11-18

### Added

* **Context Reporter Hub.** A new destination sends the reports of all your TYPO3 installations to one hosted Hub inbox. Paste the webhook URL and signing secret of a Hub site in the new settings section "Context Reporter Hub". After sending, the reporter sees the Hub reference of the report (for example `LW-152`) with a link to it, and the report history shows it too.
* **Hub connection test and status.** "Test connection" checks the URL and secret without creating a report. The status panel explains problems in plain words (wrong secret, unknown or disconnected site, Hub not reachable) and points out a webhook that still sends to the same Hub site. A Hub card in System › Context Reports shows the last delivery, the last failure and the last test.
* **Formatted report emails.** Recipients get a formatted email together with a plain-text version for email programs without formatting. It is on by default and can be switched off for ticket systems or parsers that expect plain text. New markers for plain-text templates include the Hub reference.
* **Report history for editors.** Administrators can grant System › Context Reports to backend groups. Editors with access see all reports, their review state and delivery results, and can retry deliveries and download reports. Settings, connection tests, cleanup, deleting reports and the addresses that reports are sent to stay with administrators.
* **New version notice.** Administrators see one line in System › Context Reports when a newer stable release is available, with a link to the release notes. Nothing is downloaded or installed, the check sends no data about the installation, and the environment variable `CONTEXT_REPORTER_RELEASE_CHECK_URL=off` switches it off.
* **Privacy guidance.** The report dialog reminds reporters not to include passwords or other secrets and to cover sensitive parts of a screenshot with the Redact tool. A new documentation section, "Sensitive information in reports", explains what a report contains and where it goes.
* **Hub address for links**, for installations that reach the Hub at another address than the browser, for example through an internal host name.

### Changed

* **Reliable delivery.** Webhook and Hub deliveries have a firm total time limit, so a slow endpoint no longer holds up the backend. Sending a report again after a lost answer, submitting the same report twice at once, or retrying a delivery twice at the same time no longer delivers it twice; when such requests get in each other's way, the delivery stays in the history and can be retried.
* **Confirmed Hub deliveries.** A report counts as received only when the Hub confirms it. If the confirmation is lost on the way, the reporter reads "Not confirmed by Context Reporter Hub (it may have arrived)" and the delivery can be retried safely: the Hub keeps one report per report ID.
* **Safer updates.** If the database update is missing after updating the extension, reports are still saved, nothing is sent, and System › Context Reports shows "Database update required". After the update, each held delivery is sent once on retry.
* **Understandable errors.** Failed deliveries say what went wrong (for example "No answer within the time limit" or "The host name could not be resolved") instead of technical library messages.
* **Email order and content.** The Hub is delivered before the email, so the email can name the Hub reference. The plain-text email labels each link by what it opens and only lists attachments that are attached. A custom plain-text template is sent as plain text only, so the email contains exactly what the template contains.
* **Report dialog.** The dialog asks before it discards a screenshot, the keyboard moves straight to the screenshot editor, and the editor toolbar fits narrow windows and phones.
* Times in System › Context Reports name the server's time zone, and translation notices name the object, for example "Content element not translated into: German".

### Fixed

* After an update without the database step, the report dialog could show a server error although the report had been sent.
* Damaged or incomplete screenshots were stored as broken images; they are now refused with a message.
* Ticket references in webhook answers that arrive in several pieces were sometimes lost.
* The plain-text email mentioned a JSON attachment when none was attached, and did not mention the screenshot.
* The link of a reported file now reads "Open containing folder in TYPO3", because it opens the folder.
* The report dialog now announces a missing title to screen readers.

### Security

* Administrator-only actions are checked on the server for every request; users without access see a TYPO3 page that says why.
* Reporters whose backend groups restrict the languages ("Allowed languages") can no longer report translations in other languages.
* The Hub's answers are checked strictly: links are only shown when they point into the Hub, and only known values are kept.
* Passwords, tokens and the site token of a Hub webhook URL are hidden in delivery errors, the history and log entries, also when an answer repeats them.
* Webhook URLs with a user name and password (`https://user:password@…`) are refused; credentials belong in the authentication header.

### Compatibility

* TYPO3 13.4 LTS and TYPO3 14.3 LTS, PHP 8.2, 8.3, 8.4 and 8.5, with the same package. Tested with MariaDB 10.11, MySQL 8.0 and SQLite; PostgreSQL has not been tested.
* The report format stays `context-reporter.report.v1`, and the webhook request is unchanged.
* The database gets one new column (`tx_contextreporter_delivery.hub_receipt`); run the database update after updating.

### Upgrade notes

* Update with `composer require priebera/typo3-context-reporter:^0.4` (`composer update` stays on 0.3), then run `vendor/bin/typo3 extension:setup` and `vendor/bin/typo3 cache:flush`. In classic mode, upload the new ZIP with "Overwrite" and run "Analyze Database Structure". Until the database is updated, reports are saved but not sent. See "Upgrade" in the documentation.
* Backend groups that already list the module "Context Reports" now get the report history. Check which groups list it.
* A webhook whose URL is a Hub address ending in `/in/s_…` (how 0.3 connected the Hub) now counts as delivered only with the Hub's confirmation. Moving the connection to the new "Context Reporter Hub" section is recommended; switch the webhook off afterwards, so reports are not sent twice.
* Report emails include a formatted version by default. Switch off "Send a formatted HTML version" if a ticket system or parser reads them. Emails with a custom plain-text template stay plain text.
* A stored webhook URL with a user name and password is no longer used: move the credentials to the authentication header. The sender address of report emails needs a full domain (for example `typo3@example.com`); otherwise the TYPO3 default sender is used.

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
