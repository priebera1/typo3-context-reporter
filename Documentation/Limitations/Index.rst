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
    user groups, "Hidden in menus", "Extend to subpages", the translation
    behaviour of pages, translations and the workspace state). It does not
    check the website: templates and TypoScript, caches and CDNs, extensions
    with their own access rules, the visitor's login, language fallbacks,
    mount points and shortcuts can change what visitors see. Only connected
    translations are found, not content created independently in a language
    (free mode). Parent pages are read up to 20 levels and at most 30
    languages are listed.

Website address
    The address is built by TYPO3's router from the page and the site
    configuration, like the :guilabel:`View` button does. Whether visitors
    get the page, a fallback language or an error, and which route enhancer,
    base variant or redirect applies, is decided when the page is requested
    and is not evaluated. Addresses of records with their own detail page
    (e.g. news, configured with ``TCEMAIN.preview``) are not built; the
    report contains the address of the page the record is stored on, if that
    page has one.

Placement
    The columns are the ones TYPO3 offers in the column field of the editing
    form, from the backend layout of the page and the item functions of
    extensions. TSconfig that adds or removes column items is not applied,
    like in the Page module. Extensions that place elements in other columns
    without offering them in the column field (e.g. some grid or container
    extensions) make such elements appear as "not a column of the backend
    layout". If the column item function of an extension fails, TYPO3 shows
    its usual error message to the reporter. The backend layout and where it
    is set follow the rule TYPO3 documents for the two backend layout fields,
    through parent pages the reporter may access (up to 20 levels). For
    :guilabel:`Show content from page`, the default language page is
    evaluated, and at most 100 pages that show the content are checked and 10
    named. Whether the website shows the content depends on the templates.

File checks
    Referenced files are checked with the file index of TYPO3: a file that
    was removed from the storage without TYPO3 noticing counts as present
    until the file index is updated, e.g. by the scheduler task "File
    Abstraction Layer: Update storage index". Only a reported file is looked
    up in its storage. File fields in FlexForms (plugin settings) and display
    conditions of fields are not evaluated, and image processing, file
    permissions of the web server and the frontend output are not checked.
    File types are checked like TYPO3 checks them when a record is saved:
    the extension of the file name against ``allowed`` and ``disallowed`` of
    the field (with the overrides of the record type), not the extension
    stored in the file index and not the MIME type of the file. At most 100
    references are checked and 10 problems listed.

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

File usage
    Reports about files list the file references of the reporter's current
    workspace. Links to a file in texts (soft references, e.g. in the rich
    text editor) and usages in TypoScript or templates are not counted. At most
    100 references are checked and 10 usages named. Metadata values of files
    are not collected.

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
    Reports of editors contain permission facts: table and page permissions,
    edit locks, language, record type, page type, field availability and
    file permissions. They never say whether something can be edited: TYPO3's
    complete check is internal API and also depends on workspace rules (live
    editing, stages), hooks and event listeners of extensions, which are not
    evaluated. Display conditions of fields, TSconfig other than
    ``TCEFORM.<table>.<field>.disabled`` and the permissions of the workspace
    are not part of the report. At most 20 fields are named per list.
    Reports of administrators contain no permission facts.

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
