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

..  _upgrade-procedure:

Updating the extension
======================

..  code-block:: bash

    composer update priebera/typo3-context-reporter
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

``extension:setup`` adds new database tables and columns. Run it, or
:guilabel:`Admin Tools > Maintenance > Analyze Database Structure`, before
the extension is used: until the database is updated, the report dialog and
the report history can fail with a database error.

In classic mode, update the extension in :guilabel:`Admin Tools >
Extensions`, then analyze the database structure and flush the caches.

..  _upgrade-typo3:

TYPO3 upgrades
==============

Context Reporter supports TYPO3 13.4 LTS and TYPO3 14.3 with the same
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
