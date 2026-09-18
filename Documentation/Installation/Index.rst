:navigation-title: Installation

..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

..  list-table::
    :header-rows: 1

    *   -   Requirement
        -   Supported

    *   -   TYPO3
        -   13.4 LTS and 14.3

    *   -   PHP
        -   8.2, 8.3 and 8.4

    *   -   Required TYPO3 system extensions
        -   ``typo3/cms-core``, ``typo3/cms-backend``, ``typo3/cms-filelist``

    *   -   Optional
        -   ``typo3/cms-workspaces`` (workspace titles in reports)

    *   -   Database
        -   Tested with MariaDB 10.11, MySQL 8.0 and SQLite. PostgreSQL has
            not been tested.

    *   -   Browser
        -   A current desktop browser. Screen capture requires a secure
            context (HTTPS) and the Screen Capture API; it has been verified in
            Chromium. Upload and paste work without it.

The same package works on both TYPO3 branches; Composer picks the matching
dependencies. A few labels differ because TYPO3 14 renamed backend modules,
see :ref:`upgrade-typo3`.

..  _installation-composer:

Installation with Composer
==========================

..  code-block:: bash

    composer require priebera/typo3-context-reporter
    vendor/bin/typo3 extension:setup

The setup creates three database tables for reports, screenshots and the
delivery history, see :ref:`privacy-storage`. The extension also adds its
report action to the default list of primary file list actions, see
:ref:`configuration-tsconfig`.

..  _installation-classic:

Classic mode
------------

Composer mode is the tested and recommended installation method. Classic mode
(installation without Composer, through :guilabel:`Admin Tools > Extensions`)
has not been verified yet. If you use it, create the tables with
:guilabel:`Admin Tools > Maintenance > Analyze Database Structure` after the
installation.

..  _installation-first-steps:

First steps
===========

#.  Open :guilabel:`System > Context Reports` and click
    :guilabel:`Settings` at the top right.
#.  Set a project name and environment, and configure the
    :ref:`email <configuration-email>` or :ref:`webhook <configuration-webhook>`
    destination. Check the :guilabel:`Status` panel and use
    :guilabel:`Send test email` or :guilabel:`Send test webhook`.
#.  Switch the destination on, click :guilabel:`Report a problem` in the
    backend toolbar and send a first report.
#.  Check the delivery in :guilabel:`System > Context Reports`.

Without a configured destination, reports are stored in the report history
and can be downloaded as JSON or Markdown.

Decide early how long reports should be kept: the default retention keeps
them forever, see :ref:`usage-retention`. If administrators should not be
able to change the webhook destination, pin it in the system configuration,
see :ref:`privacy-webhook-boundary`.
