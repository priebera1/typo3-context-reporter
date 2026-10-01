:navigation-title: Known limitations

..  _limitations:

=================
Known limitations
=================

TYPO3 versions
    TYPO3 13.4 LTS and TYPO3 14.3 LTS are supported. Older TYPO3 versions
    are not, and TYPO3 14.0 to 14.2 have not been tested.

Frontend
    There is no report button in the frontend. Frontend problems are
    reported from the Page or Preview module, see :ref:`usage-frontend`.
    Content elements shown in the preview are not identified; the report
    refers to the page.

Visibility settings
    The report lists stored settings (hidden, start and end time, frontend
    user groups, "Hidden in menus", "Extend to subpages", translations and the
    workspace state). It does not check the website: templates and
    TypoScript, caches and CDNs, extensions with their own access rules,
    the visitor's login, language fallbacks, mount points and shortcuts can
    change what visitors see. Content in a column the page layout does not
    show is not detected. The translation behaviour of pages ("Hide default
    language of page", "Hide page if no translation for current language
    exists") is not evaluated yet. Only connected translations are found,
    not content created independently in a language (free mode). Parent
    pages are read up to 20 levels and at most 30 languages are listed.

File checks
    Referenced files are checked with the file index of TYPO3: a file that
    was removed from the storage without TYPO3 noticing counts as present
    until the file index is updated, e.g. by the scheduler task "File
    Abstraction Layer: Update storage index". Only a reported file is looked
    up in its storage. File fields in FlexForms (plugin settings) and display
    conditions of fields are not evaluated, and image processing, file
    permissions of the web server and the frontend output are not checked. At
    most 100 references are checked and 10 problems listed.

Languages
    A page is reported in one language. When several languages are shown
    side by side, the report uses the default language unless exactly one
    translation is selected, see :ref:`usage-language`.

Field-level reporting
    Reports refer to pages, records, files, folders and backend views. The
    editing form button reports the record, not a single field of the form.

Content elements in the Page module
    Neither TYPO3 13.4 nor 14.3 has an API to add an action to the header of
    a content element in the Page module; the header actions are part of a
    Core template. Report content elements through their :guilabel:`⋮` menu
    (context menu), the record list or the editing form.

File list views
    Only the list view of the file list has report buttons. In the tile
    view, files and folders are reported through their context menu.
    Projects that define ``options.file_list.primaryActions`` themselves need
    to add ``contextReporterReport`` to see the button.

File references
    Reports about files do not list where a file is used (references), and
    metadata values are not collected.

Screen capture
    Screen capture uses the browser's Screen Capture API. It requires a
    secure context (HTTPS or ``localhost``) and a desktop browser. It has
    been verified in Chromium; other browsers show their own picker and may
    behave differently. Where screen capture is unavailable or declined,
    upload or paste a screenshot instead.

Redaction
    Redaction covers an area with an opaque box. There is no blur or pixelate
    tool, because an opaque box is the safer choice.

Delivery
    Deliveries are sent while the reporter waits, so a slow endpoint delays
    the dialog (up to the configured timeout). There is no queue and no
    automatic retry. Failed deliveries can be retried manually.

Duplicate deliveries
    A delivery that timed out or failed may still have been processed by the
    receiver. A manual retry then sends the report again. Receivers should use
    the report ID to detect duplicates.

Report history
    The report history, the settings and the review state are available to
    administrators only. Other backend users see the result of their own
    report in the dialog and can download it, but they cannot browse the
    history.

Review state
    Reports are either open or resolved. There are no further states,
    assignments, priorities or comments.

Workspaces
    A report made in a workspace refers to the live UID of the record and
    shows the label of the workspace version. Recipients who follow the
    backend link see the draft only when they work in the same workspace.

Edit permissions
    Reports only reference pages, records, files and folders the reporter can
    access, but they do not contain a snapshot of the reporter's edit
    permissions (for example field or language restrictions). The complete
    record edit check of TYPO3 is internal API.

Storage
    Reports and screenshots are stored in the TYPO3 database. The database
    grows with every report until reports are deleted or removed by the
    cleanup, see :ref:`privacy-storage`.

Retention
    The cleanup only runs when an administrator starts it in the settings or
    runs ``context-reporter:cleanup``. Schedule the command yourself (cron or
    the Scheduler task :guilabel:`Execute console commands`) to apply the
    retention regularly.

Integrations
    There are no native connectors for Jira, GitLab, GitHub, Slack or other
    systems. Use the webhook, for example with n8n, Make or Zapier.

Switched users
    When an administrator uses :guilabel:`Switch to user`, the report is
    created by the switched-to user. The original administrator is not
    recorded.
