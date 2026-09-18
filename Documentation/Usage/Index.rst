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
            form, the page selected in a page module, the folder open in the
            file list, or otherwise the backend module

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
