:navigation-title: Usage

..  _usage:

=====
Usage
=====

..  _usage-report:

Reporting a problem
===================

#.  Start the report where the problem is, see :ref:`usage-entry-points`.
#.  Check the detected object at the top of the dialog: its type, name,
    identifier and where it lives.
#.  Enter a short title and describe what you tried to do, what you
    expected and what happened instead.
#.  Optionally add a screenshot.
#.  Open :guilabel:`Review the technical data that will be sent` to see
    exactly what the report contains.
#.  Click :guilabel:`Send report`, or :guilabel:`Save and download` to
    save the report and download it as JSON.

After sending, the dialog confirms that the report was saved and shows its
ID and the delivery result per destination, including a ticket reference if
the webhook receiver returned one. It offers:

:guilabel:`Open report`
    Opens the report in :guilabel:`System > Context Reports`
    (administrators only).

:guilabel:`Copy`
    Copies a short text summary, the Markdown version, the JSON document
    without the screenshot data, or the link to the report.

:guilabel:`Download`
    Downloads the Markdown version, the JSON document (including the
    screenshot) or the screenshot.

If the dialog is closed before the report is sent, the title and description
are kept for 30 minutes, unless the backend is reloaded.

..  _usage-entry-points:

Entry points
------------

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   Where
        -   Reported object

    *   -   :guilabel:`Report a problem` in the backend toolbar
        -   Detected from the current view: the record open in the editing
            form, the page selected in a module with the page tree (in the
            language shown there), the folder open in the file list, or
            otherwise the backend module

    *   -   Report action of a row in :guilabel:`Web > List`
        -   The page or record of the row

    *   -   Report action in the document header of :guilabel:`Web > Page`
        -   The page shown in the Page module

    *   -   Report action in the list view of :guilabel:`File > Filelist`
        -   The file or folder of the row

    *   -   Context menu of pages, records, content elements (the
            :guilabel:`⋮` button in the Page module), files and folders
            (right click in the file list, folder tree)
        -   The clicked object

    *   -   :guilabel:`Report problem` in the document header of the record
            editing form
        -   The record being edited (or the form, when several or new records
            are open)

The compact actions are icon-only buttons with the speech bubble icon; their
tooltip and accessible label read, for example,
:guilabel:`Report problem with this record`.

In a workspace, the dialog shows the record as it is in the current
workspace, for example the title of a changed draft. The report refers to
the live UID of the record. Records of other workspaces cannot be reported.

..  _usage-language:

Language
--------

Records and page translations are reported in their own language. A page is
reported in the language the reporter is looking at in the Page module, the
Preview module (:guilabel:`Web > View` in TYPO3 13.4,
:guilabel:`Content > Preview` in TYPO3 14.3) or another module with the page
tree that keeps the language selection the same way, for example the
Visual Editor. The frontend URL in the report points to that language.

*   When several languages are shown side by side, the report uses the one
    selected translation, or the default language if more than one
    translation is selected. The Preview module of TYPO3 14 decides the
    same way.
*   If the page is not translated into the selected language, or the
    reporter may not edit that language, the report uses the default
    language, like the Page module does.
*   A page reported from a module without page tree uses the default
    language.

..  _usage-visibility:

Visibility settings
-------------------

When a page or record does not appear on the website, the reason is often a
setting in TYPO3. For pages and records, the report contains these settings,
and the dialog and the report detail list the ones that keep the object from
visitors below the reported object, for example:

*   :guilabel:`Hidden`
*   :guilabel:`Publishing starts on 2026-10-15 08:00` or
    :guilabel:`Publishing ended on 2026-09-01 00:00`
*   :guilabel:`Frontend access: Members, Hide at login`
*   :guilabel:`Hidden in menus` (pages)
*   :guilabel:`Page "About us": Hidden` for a record on that page
*   :guilabel:`Parent page "Members area", applies to its subpages: Frontend
    access: Members`: a parent page with :guilabel:`Extend to subpages` passes
    its restrictions on. Only reporters who may edit that field see this.
*   :guilabel:`Translation Deutsch: Hidden`,
    :guilabel:`Not translated into: Français` or
    :guilabel:`Page not translated into: Français`, for the languages the
    reporter may use. New translations are hidden by default in TYPO3.
*   :guilabel:`New in this workspace, not on the live website yet`, or
    changed or deleted in the workspace

The settings are evaluated when the dialog opens. They are the settings
stored in TYPO3, not a check of the website: templates, caches, extensions
and other frontend logic can still change what visitors see. The technical
data contains everything, also settings that do not restrict anything, such
as a future end date.

..  _usage-file-checks:

File checks
-----------

A missing image or download is often a file problem. For a reported file,
and for the files of a reported page or record, the dialog and the report
detail list problems TYPO3 knows about under :guilabel:`File checks`, for
example:

*   :guilabel:`Not found in its storage` or :guilabel:`Marked as missing, but
    found in its storage` for a reported file: it is looked up in its storage
    when the dialog opens.
*   :guilabel:`Storage offline in the backend` and
    :guilabel:`Empty file (0 bytes)`
*   :guilabel:`Images: "team.jpg" – Reference hidden` or
    :guilabel:`Images: "team.jpg" – Marked as missing` for a file reference
    of the reported page or record
*   :guilabel:`Images: Referenced file no longer exists` for a reference to a
    file TYPO3 does not know any more
*   :guilabel:`Referenced files not checked (outside the accessible file
    mounts): 2`

A report from the metadata of a file (:guilabel:`Edit metadata` in the file
list) stays about the metadata, and its file is checked like a reported file.
Only the file fields the editing form shows for the type of the record are
checked, e.g. :guilabel:`Images` of an :guilabel:`Images Only` element but
not old references of a former type. Referenced files are checked with the
file index of TYPO3; their storage is not asked. Without problems, nothing is
shown; the technical data still says how many references were checked.

..  _usage-frontend:

Reporting frontend problems
===========================

Context Reporter has no button on the website itself. Report what you see in
the frontend from the backend:

#.  Open the page in the Preview module or the Page module and choose the
    language in which the problem appears.
#.  Click :guilabel:`Report a problem` in the backend toolbar. The report
    refers to the page and the language, and links the frontend URL of that
    translation.
#.  Add a screenshot. :guilabel:`Capture screen` captures the browser tab,
    including the preview. For the page as visitors see it, take a
    screenshot of the frontend with your operating system and paste or
    upload it.
#.  Describe which part of the page is wrong.

If the problem is that something does not appear at all, check the
:ref:`visibility settings <usage-visibility>` and the
:ref:`file checks <usage-file-checks>` the dialog lists first.

Keep in mind:

*   The report refers to the page, not to a content element of the preview.
    Name the element in the description, or report it through its
    :guilabel:`⋮` menu in the Page module.
*   The device size simulated by the Preview module is not part of the
    report. The browser details contain the size of the backend window.
*   In a workspace, the report refers to the reporter's current workspace.

..  _usage-screenshot:

Screenshots
===========

Screenshots are created and edited in the browser. Nothing is uploaded before
the report is sent.

Capture screen
    The browser asks which tab, window or screen to share. The dialog hides
    while the picture is taken. Only one frame is captured and the capture
    stops immediately.

Upload image
    PNG, JPEG, WebP, GIF or BMP files.

Paste or drop
    Paste an image with :kbd:`Ctrl+V` / :kbd:`Cmd+V`, or drop an image file
    onto the screenshot area.

The editor provides these tools:

..  list-table::
    :header-rows: 1

    *   -   Tool
        -   Key
        -   Purpose

    *   -   Select
        -   :kbd:`V`
        -   Move, resize, recolor or delete annotations

    *   -   Rectangle
        -   :kbd:`R`
        -   Frame an area

    *   -   Arrow
        -   :kbd:`A`
        -   Point at something

    *   -   Draw
        -   :kbd:`D`
        -   Draw freehand, for example to circle or underline something

    *   -   Text
        -   :kbd:`T`
        -   Add a note

    *   -   Redact
        -   :kbd:`B`
        -   Cover confidential content with an opaque black box

Annotations use one of five colors: red, orange, green, blue and black.

Existing annotations can be selected with every tool: click an annotation to
select it, then drag it to move it, use the handles to resize it, pick a
color to recolor it (redactions stay black), or press :kbd:`Delete` /
:kbd:`Backspace` or the trash button to remove it. :kbd:`Esc` clears the
selection. With the select and text tools, notes can be dragged right away.
To change a note, click it with the text tool, double-click it, or select it
and press :kbd:`Enter`; an emptied note is removed.

Undo and redo are available as buttons and with :kbd:`Ctrl+Z` /
:kbd:`Cmd+Z` and :kbd:`Ctrl+Y` / :kbd:`Shift+Cmd+Z`. When the report is sent, annotations
and redactions are merged into the image, so redacted content cannot be
recovered. Large screenshots are scaled down and compressed to stay below
:confval:`setting-reporting-maxscreenshotsizekb`.

..  _usage-history:

Report history
==============

:guilabel:`System > Context Reports` lists the reports, newest first. Two
filters can be combined:

*   the review state: :guilabel:`Open` (the default), :guilabel:`Resolved` or
    :guilabel:`All`, each with the number of reports,
*   the delivery state: :guilabel:`Delivered`, :guilabel:`Partially
    delivered`, :guilabel:`Delivery failed` or :guilabel:`Stored locally`.

Each row shows the report title and ID, the reported object with its
identifier and page or folder, the reporter, the creation time, the review
state and the delivery state. Long titles are shortened; content elements
with a long title are listed by their type, for example
:guilabel:`Plain HTML` with ``tt_content:142 · About us``. A click anywhere on
a row opens the report; the title is a regular link.

..  figure:: /Images/context-reporter-report-detail.png
    :alt: A saved report in System > Context Reports with its state, the reported object, the screenshot, the description, the reporter and the delivery history, and the Copy and Download actions

    A saved report with the reported object, the screenshot and the delivery history

The detail view shows, in this order:

*   title, report ID, creation time and entry point, the review and delivery
    state, and :guilabel:`Mark as resolved` or :guilabel:`Reopen`,
*   the reported object with its identifier, page, site, language,
    workspace and module, and the actions :guilabel:`Open in TYPO3`,
    :guilabel:`Edit metadata` (files) and :guilabel:`Open frontend` (pages and
    records),
*   the screenshot,
*   the description, the reporter and the delivery history with destination,
    time, attempt, result, HTTP status code, safe error message, external
    ticket reference and the user who triggered the delivery or download,
*   :guilabel:`Technical details`, collapsed by default: all attached data as
    tables and as raw JSON,
*   :guilabel:`Delete report`, separated from everything else. It asks for
    confirmation.

The document header offers:

:guilabel:`Copy`
    Copies a short text summary, the Markdown version, the JSON document
    without the screenshot data, or the link to the report to the
    clipboard.

:guilabel:`Download`
    Downloads the Markdown version, the complete JSON document including the
    screenshot (base64), or the screenshot itself.

Failed deliveries can be retried as long as the destination is enabled.
Apart from the delivery history and the review state, reports cannot be
changed.

..  _usage-review:

Open and resolved reports
=========================

New reports are open. When a problem has been dealt with, an administrator
marks the report as resolved; the report remembers who resolved it and when.
Resolved reports can be reopened. The review state is independent of the
delivery state and is not part of downloads, emails or webhook payloads.
Context Reporter deliberately has no assignments, priorities, comments or
due dates: use your ticket system for those.

..  _usage-retention:

Retention and cleanup
=====================

Reports stay in the database until they are deleted or removed by the
cleanup. Deleting a report always deletes its screenshot and delivery history
as well.

The retention is configured with :confval:`setting-reporting-retentiondays`
and defaults to keeping reports forever. Nothing is removed automatically:
the cleanup runs only when an administrator starts it.

In :guilabel:`System > Context Reports > Settings > Reports & storage`, the
cleanup panel shows how many reports (and how many of them are still open),
screenshots with their size, and delivery attempts would be removed, and
asks for confirmation before it removes them. The cleanup also removes
screenshots and delivery attempts whose report no longer exists.

The same cleanup is available as console command:

..  code-block:: bash

    # Show what the configured retention would remove
    vendor/bin/typo3 context-reporter:cleanup --dry-run

    # Apply the configured retention
    vendor/bin/typo3 context-reporter:cleanup

    # Remove reports older than 90 days, regardless of the configured retention
    vendor/bin/typo3 context-reporter:cleanup --days=90

With the retention "keep forever", the command removes no reports unless
``--days`` is given. The command can run from cron or as
:guilabel:`Execute console commands` task of the TYPO3 Scheduler.
