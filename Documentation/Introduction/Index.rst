:navigation-title: Introduction

..  _introduction:

============
Introduction
============

..  _introduction-what:

What does it do?
================

When an editor notices a problem in the TYPO3 backend, the hardest part for
the support team is usually to find out *where* it happened. Context Reporter
answers this question automatically:

..  code-block:: text

    problem → reporter → structured report → download / email / webhook → external system

An editor can start a report where the object is:

*   the :guilabel:`Report a problem` button in the backend toolbar,
*   the report action of a row in :guilabel:`Web > List`,
*   the report action in the document header of :guilabel:`Web > Page`,
*   the report action of a file or folder in the list view of
    :guilabel:`File > Filelist`,
*   :guilabel:`Report a problem with this page`,
    :guilabel:`Report a problem with this record`,
    :guilabel:`Report a problem with this file` or
    :guilabel:`Report a problem with this folder` in the context menu,
*   the :guilabel:`Report problem` button in the document header of the record
    editing form.

All entry points use the same speech bubble icon.

The report dialog shows the detected object as a compact card, for example
:guilabel:`Page Content · Text & Media`, :guilabel:`Our story`,
``tt_content:2 · About us`` and ``main · English · Live · Web › List``. It asks
for a short title and an optional description and screenshot, and shows all
technical data that will be sent.

..  figure:: /Images/context-reporter-dialog.png
    :alt: The report dialog with the detected content element "Our story", its page, site, language, workspace and module, a title, a description and an annotated screenshot with a redacted area

    The report dialog with the detected context and an annotated screenshot

..  _introduction-context:

Collected context
=================

Subject
    The page, record, file, folder or backend view the report is about, with
    its label, type and a backend link.

Page
    UID, PID, title, slug, page type, hidden flag, rootline, backend link,
    edit link and frontend URL.

Record
    Table, UID, PID, label, record type with its label, language,
    translation source, ``colPos``, hidden flag, workspace version and backend
    link. Apart from the label (the title TYPO3 shows for the record), no
    field values are collected; multi-line text such as the body text of a
    content element is never used as label.

File
    ``sys_file`` UID, storage UID, identifier, name, extension, MIME type,
    size, missing flag, metadata UID, and links to the file list and the
    metadata record. File contents and metadata values are not collected.

Folder
    Storage UID, identifier, name and a file list link. Reports about a file
    include its folder.

Storage
    UID, name, driver type, whether it is public and whether it is offline.
    The storage configuration is not collected.

Editing form
    Edited records, defaults of a new record (type, language and ``colPos``
    only) and restricted fields.

Site, language, workspace
    Site identifier, base and root page; language ID, title and locale;
    workspace ID and title.

Backend
    Module, route and backend language. Tokens and return URLs are removed.

System
    TYPO3 and PHP versions, application context, Composer mode, database
    platform, operating system and extension version.

Browser
    Browser, operating system, user agent, languages, time zone, window and
    screen size, pixel ratio, color scheme and reduced motion preference (can
    be disabled).

Reporter
    UID and username by default. Real name, email address and groups are
    opt-in.

Recent backend errors
    Opt-in: the reporter's own error and warning entries of the backend log,
    see :ref:`privacy-recent-errors`.

The report dialog shows all of this before the report is sent. See
:ref:`privacy-security` for what is deliberately not collected.

..  _introduction-scope:

What it is not
==============

Context Reporter is intentionally not a ticket system. It has no boards,
workflows, assignments, priorities, comments, due dates, SLAs or time
tracking, and no native Jira, GitLab, GitHub or Slack connectors. The local
:guilabel:`Context Reports` module is an audit log with manual retry, copy
and download actions, and a simple :ref:`open/resolved marker <usage-review>`.
Use the :ref:`webhook <integration-webhook>` to connect any other system.

All features are part of the extension. There is no paid edition that unlocks
functionality.
