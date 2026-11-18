:navigation-title: Upgrade

..  _upgrade:

=======
Upgrade
=======

..  _upgrade-versioning:

Versioning
==========

Context Reporter follows semantic versioning (``MAJOR.MINOR.PATCH``). Before
version 1.0, a minor version (``0.x.0``) can contain changes that require
action; they are listed in :file:`CHANGELOG.md` of the repository.

The report document has its own version, ``schema`` in the JSON document
(currently ``context-reporter.report.v1``). New fields can be added without
changing it, so receivers should ignore unknown fields.

..  _upgrade-notice:

New version notice
==================

When a newer stable release is published, administrators see a single line at
the top of :guilabel:`System > Context Reports`, for example
"Context Reporter 0.5.0 is available. You are using 0.4.0.", with a
:guilabel:`What's new` link to the release notes. It is information only:
nothing is downloaded or installed, update as described below.

*   Close the notice with :guilabel:`×` to hide it for that release. It stays
    hidden for you (other administrators still see it) until a later release
    is published.
*   Editors who were granted the module do not see the notice.
*   Development versions (e.g. ``dev-main``) and pre-releases never show it.

The extension asks the public release endpoint of Context Reporter Hub at most
every 12 hours; see :ref:`privacy-update-check` for what that request contains
and how to switch it off.

..  _upgrade-procedure:

Updating the extension
======================

Every update has four steps, in this order:

#.  **Update the code.** Before version 1.0, every new minor version needs a
    new Composer requirement: ``^0.3`` does not include 0.4. For 0.4:

    ..  code-block:: bash

        composer require priebera/typo3-context-reporter:^0.4

    ``composer update priebera/typo3-context-reporter`` only installs patch
    versions of the version you already require (for example 0.4.0 to
    0.4.1). In classic mode, upload the ZIP file of the new version in
    :guilabel:`Admin Tools > Extensions` (:guilabel:`System > Extensions` in
    TYPO3 14) with :guilabel:`Overwrite` checked.
#.  **Update the database:** ``vendor/bin/typo3 extension:setup``, or
    :guilabel:`Admin Tools > Maintenance > Analyze Database Structure` and
    apply the suggested changes for the ``tx_contextreporter_*`` tables. The
    user that runs the command must be able to write the TYPO3 caches
    (:file:`var/cache`), usually the web server user; otherwise use the
    Install Tool.
#.  **Flush the caches:** ``vendor/bin/typo3 cache:flush``, or
    :guilabel:`Admin Tools > Maintenance > Flush TYPO3 and PHP Cache`.
#.  **Check the module:** open :guilabel:`System > Context Reports`. It lists
    your reports without :guilabel:`Database update required`, and the
    :guilabel:`Status` panels of the settings show your destinations as ready.

If the database step was skipped or failed, :guilabel:`System > Context
Reports` and its settings show :guilabel:`Database update required` with the
missing columns once the caches are flushed. Reports are still saved and can
be downloaded, but nothing is sent: every enabled destination gets a delivery
marked "Not sent" in the report history, so nothing leaves the installation
that the delivery history could not record. Run the database step, then retry
these deliveries; each is sent exactly once.

..  _upgrade-0-4:

Updating to 0.4
---------------

From 0.3 (Composer mode):

..  code-block:: bash

    composer require priebera/typo3-context-reporter:^0.4
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Then open :guilabel:`System > Context Reports` and check it as described in
:ref:`upgrade-procedure`. What changes:

*   The database step is required: the table ``tx_contextreporter_delivery``
    gets the column ``hub_receipt`` (what the Hub answered about the
    screenshot and its storage, see :ref:`integration-hub-screenshots`).
    Without the column, deliveries are held as "Not sent" until the update.
*   :guilabel:`System > Context Reports` can now be granted to editors: their
    backend group needs the module :guilabel:`Context Reports` under
    :guilabel:`Access Lists > Modules`, see :ref:`configuration-access`.
    Groups that already list the module get the report history, the review
    state and retries (never the settings, tests, cleanup, deleting or the
    addresses reports are sent to), so check which groups list it.
*   New optional destination :ref:`Context Reporter Hub <integration-hub>`
    with the setting :confval:`setting-hub-publicurl` for a Hub that the
    browser opens at another address than TYPO3.
*   A webhook whose URL is a Hub intake address (``…/in/s_…``, how 0.3
    connected the Hub) now counts as delivered only with the Hub's
    acknowledgement, like the new Hub destination; an answer without a Hub
    reference (a proxy or login page answering ``200``) is a failed delivery
    that can be retried. Moving the connection to the Hub destination is
    recommended; other webhook URLs are unchanged.
*   Webhook timeouts are now a total time limit including the answer, and the
    answer is read completely up to 64 KB, also when it arrives in pieces.
*   Sending the same report again from the dialog after a lost answer returns
    the stored report instead of creating a second one.
*   Screenshots that are cut off or damaged are refused with a message;
    previously they could be stored as broken images.
*   Report emails get a formatted HTML version next to the plain text (on by
    default, :confval:`setting-email-htmlversion`). Switch it off if a ticket
    system or parser reads the emails and expects plain text. Emails with a
    custom plain-text template stay plain text only, so a template that
    leaves out parts of the report (for example the description or the
    technical context) keeps doing so.
*   The shipped plain-text template changed: it names the time zone of the
    server next to UTC, labels its links by what they open (:guilabel:`Report
    in TYPO3`, :guilabel:`Content element in TYPO3`), names the Hub reference,
    lists only the attachments that are attached and says when replies go to
    the reporter, with new :ref:`markers <integration-email-markers>`. Custom
    templates keep working unchanged; compare them with the shipped
    :file:`Report.txt` to use the new markers.
*   The Hub is now delivered before the email (previously after it), so the
    email can name the Hub reference. A failing Hub delays the email by about
    the Hub's time limit of 5 seconds and never stops it.
*   The webhook URL may no longer contain a user name or password
    (``https://user:password@…``); use the authentication header instead. A
    stored URL with credentials is not used until it is changed.
*   The sender address of report emails needs a full domain
    (``typo3@example.com``); addresses like ``typo3@localhost`` fall back to
    the TYPO3 default sender.
*   Users whose backend groups restrict the languages
    (:guilabel:`Allowed languages`) can no longer report translations in other
    languages, like TYPO3's page and list modules do not show them.
*   Times in :guilabel:`System > Context Reports` name the time zone of the
    server.

..  _upgrade-0-3:

Updating to 0.3
---------------

*   The database schema is unchanged. Flush the caches after updating.
*   Reports contain new optional sections, see :ref:`integration-webhook`:
    ``routing``, ``placement``, ``fileUsage`` and ``permissions``, and new
    fields. ``context.page.frontendUrl`` is now left out when TYPO3 has no
    address for the page (e.g. folders, pages deleted in the workspace);
    ``context.routing`` explains why. Receivers that require the frontend URL
    should handle its absence.
*   Reports of editors contain their permission facts for the reported
    object, see :ref:`privacy-permissions`. Check that everyone who receives
    reports may see them.
*   The default email template has a new :guilabel:`FINDINGS` section with
    the marker ``{context.findings}``. Custom templates keep working; add the
    marker if you want the findings in your emails.

..  _upgrade-typo3:

TYPO3 upgrades
==============

Context Reporter supports TYPO3 13.4 LTS and TYPO3 14.3 LTS with the same
package, so an upgrade of TYPO3 from 13.4 to 14.3 needs no other version of
the extension. Run the database update after the TYPO3 upgrade, as for every
upgrade:

..  code-block:: bash

    vendor/bin/typo3 extension:setup

The database tables of the extension are the same on both TYPO3 versions:
stored reports, screenshots, the delivery history and the review state are
kept, and the settings stay in the TYPO3 registry.

..  _upgrade-typo3-differences:

Differences between TYPO3 13.4 and 14.3
---------------------------------------

The extension behaves the same on both branches. Two things look different
because TYPO3 changed them, not the extension:

*   **Backend modules were renamed in TYPO3 14.** Reports name the module the
    reporter was in, so the same situation reads
    :guilabel:`Web > Page` on TYPO3 13.4 and :guilabel:`Content > Layout` on
    TYPO3 14.3, likewise :guilabel:`Web > List` → :guilabel:`Content >
    Records`, :guilabel:`File > Filelist` → :guilabel:`Media` and
    :guilabel:`Site Management > Sites` → :guilabel:`Sites > Setup`. Reports
    created before the upgrade keep the labels that were stored with them.
*   **The file list actions of TYPO3 14 are split before extensions are
    asked.** ``options.file_list.primaryActions`` still decides whether
    :guilabel:`Report problem` is a direct action or an entry of the
    :guilabel:`More options` menu, and
    :file:`Configuration/user.tsconfig` still lists it, but on TYPO3 14 the
    extension evaluates the option itself. Projects that override the option
    have to include ``contextReporterReport`` on both branches.
