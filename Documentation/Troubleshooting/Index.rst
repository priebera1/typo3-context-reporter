:navigation-title: Troubleshooting

..  _troubleshooting:

===============
Troubleshooting
===============

..  _troubleshooting-no-button:

The report button is missing
============================

*   Reporting can be disabled for users and groups with
    ``options.contextReporter.enable = 0`` or for everybody with
    :confval:`setting-general-enabled`.
*   The tile view of the file list has no action buttons. Switch to the list
    view or use the context menu of the file or folder.
*   If your project sets ``options.file_list.primaryActions`` itself, add
    ``contextReporterReport``, see :ref:`configuration-tsconfig`.
*   Content elements in the Page module have no report button. Use their
    :guilabel:`⋮` menu.
*   After an update, flush the caches and reload the backend.

..  _troubleshooting-not-available:

The dialog says the object is not available
===========================================

The dialog only accepts objects that the reporter can access. The message
"The selected page, record, file or folder is not available to you." appears
when the page, record, file or folder

*   is outside the web mounts, file mounts or permissions of the reporter,
*   belongs to another workspace than the current workspace of the reporter,
*   is file metadata of a file the reporter cannot access, or
*   has been deleted in the meantime.

Report the problem from the toolbar instead: the report then refers to the
backend view.

..  _troubleshooting-language:

The report shows the default language
=====================================

Pages are reported in the language selected in the Page module, the Preview
module or another module with the page tree, see :ref:`usage-language`. The
default language is used when

*   several translations are shown side by side,
*   the page is not translated into the selected language (in the current
    workspace),
*   the reporter may not edit the selected language, or
*   the page was reported from a module without page tree.

..  _troubleshooting-visibility:

The visibility settings do not match the website
================================================

The dialog lists the settings stored in TYPO3 when it opened, see
:ref:`usage-visibility`. The website can differ because of cached pages,
a reverse proxy or CDN, the template, extensions or the visitor's login.
Restrictions of parent pages ("Extend to subpages") are only listed for
reporters who may edit that field, and only up to the first parent page they
can access.

..  _troubleshooting-website-address:

The report has no website address
=================================

The :guilabel:`Website address` findings say why, see
:ref:`usage-website-address`: the page belongs to no site, TYPO3 offers no
view for its page type (folders, spacers, or page TSconfig
``TCEMAIN.preview.disableButtonForDokType``), the site has no such language,
or the page is deleted in the reporter's workspace. Records with their own
detail page, e.g. news, get the address of the page they are stored on only.

..  _troubleshooting-context-menu:

The context menu of a file or folder shows an error
===================================================

TYPO3 13.4 and 14.3 fail with "Call to a member function
checkActionPermission() on null" when the context menu of a file or folder
is opened that TYPO3 cannot find any more, for example because it was
deleted, renamed or moved after the file list was loaded. The error comes from
the file list of TYPO3 and also occurs without Context Reporter. Reload the
file list. Context Reporter leaves its :guilabel:`Report a problem` item out
for files and folders it cannot resolve or access.

..  _troubleshooting-file-types:

A file of the wrong type is not mentioned in the file checks
============================================================

The file checks name the file types TYPO3 removes when the record is saved,
see :ref:`usage-file-checks`. The type is the extension of the file name, not
the extension stored in the file index. Image fields allow the image file types
of TYPO3 (``$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']``), which
include ``pdf``, ``ai`` and ``svg`` by default, so a PDF in an image field is
not a problem for TYPO3.

..  _troubleshooting-permissions:

The permission facts look complete, but the record cannot be edited
=====================================================================

The permission facts are inputs of TYPO3's checks, not its decision, see
:ref:`usage-permissions`. Workspace rules, hooks and extensions can deny
editing anyway. Use :guilabel:`Switch to user` in the Backend users module to
see the backend as the reporter does.

..  _troubleshooting-classic-upload:

The Extension Manager says the extension is not available
=========================================================

In classic mode, the upload of the ZIP file ends with "Extension
context_reporter is not available" when the Extension Manager installs
uploaded extensions automatically. Switch off the automatic installation,
upload the file again and activate the extension, see
:ref:`installation-classic`.

..  _troubleshooting-email:

Reports do not arrive by email
==============================

Open :guilabel:`System > Context Reports > Settings > Email` and check the
:guilabel:`Status` panel, see :ref:`configuration-email-status`. In most
cases email delivery is switched off, no recipient is saved, or the TYPO3
mail transport is ``null``, ``mbox``, a spool or a local mail catcher such as
Mailpit in DDEV. Use :guilabel:`Send test email` after every change. The
delivery history of a report shows transport errors without credentials.

..  _troubleshooting-webhook:

Webhook deliveries fail
=======================

*   Check the :guilabel:`Status` panel on the :guilabel:`Webhook` tab and use
    :guilabel:`Send test webhook`.
*   The endpoint must use HTTPS unless
    :confval:`setting-webhook-allowinsecurehttp` is enabled, and must answer
    with a ``2xx`` status code. Redirects are not followed.
*   A missing environment variable is shown as such in the status panel.
*   The delivery history shows the HTTP status code and the beginning of the
    response body.
*   Slow endpoints fail after :confval:`setting-webhook-timeout` seconds.
*   For the n8n workflow, see :ref:`integration-n8n-troubleshooting`.

..  _troubleshooting-screenshot:

A report with a screenshot cannot be sent
=========================================

*   The screenshot is larger than
    :confval:`setting-reporting-maxscreenshotsizekb` after compression.
*   PHP rejects the request: ``upload_max_filesize`` and ``post_max_size``
    must be larger than the screenshot limit.
*   The database rejects the query: ``max_allowed_packet`` of MariaDB or
    MySQL must be larger than the screenshot limit, see
    :ref:`privacy-storage`.

..  _troubleshooting-capture:

Screen capture is not offered
=============================

Screen capture needs a secure context (HTTPS) and a desktop browser with the
Screen Capture API. If the browser does not offer it, or the reporter
declines the picker, upload an image or paste it from the clipboard.

..  _troubleshooting-copy:

Copying does not work
=====================

Browsers can block clipboard access, especially without HTTPS. TYPO3 then
shows "Could not be copied to clipboard". Use :guilabel:`Download` instead.

..  _troubleshooting-database:

The report history shows a database error
=========================================

The database schema is older than the extension. Run
``vendor/bin/typo3 extension:setup`` or
:guilabel:`Admin Tools > Maintenance > Analyze Database Structure`, see
:ref:`upgrade-procedure`.

..  _troubleshooting-readonly:

A setting cannot be changed
===========================

Settings that are set in
``$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']`` are
read-only in the module, see :ref:`configuration-storage`.

If the settings page says that settings of a development version take
precedence, :file:`config/system/settings.php` still contains a
``context_reporter`` entry below ``EXTENSIONS`` from a pre-release
installation. Remove that entry and configure the extension in
:guilabel:`System > Context Reports > Settings`.

..  _troubleshooting-retention:

Old reports are not removed
===========================

The default retention keeps reports forever, and the cleanup only runs when
it is started. Configure :confval:`setting-reporting-retentiondays` and
schedule ``vendor/bin/typo3 context-reporter:cleanup``, see
:ref:`usage-retention`.
