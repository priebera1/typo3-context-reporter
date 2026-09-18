..  _start:

================
Context Reporter
================

:Extension key:
    context_reporter

:Package name:
    priebera/typo3-context-reporter

:Language:
    en

:Author:
    Patrik Priebera and contributors

:License:
    GPL-2.0-or-later

----

Context Reporter adds object-aware problem reports to the TYPO3 backend.
Editors report a problem where it happens, and the report automatically
carries the TYPO3 context: page, record, content type, file or folder, site,
language, workspace, backend module, system and browser. An optional
screenshot is annotated and redacted in the editor's browser.

The screenshot shows what the user sees. The TYPO3 context tells the
developer what the problem actually belongs to.

Reports are downloaded, emailed or sent to a webhook, so they end up in the
tools a team already uses. Context Reporter is not a ticket system. It
supports TYPO3 13.4 LTS and TYPO3 14.3.

----

..  toctree::
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Integration/Index
    PrivacyAndSecurity/Index
    Limitations/Index
    Upgrade/Index
    Troubleshooting/Index
    GetHelp

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What a report contains and where Context Reporter stops.

    ..  card:: :ref:`Installation <installation>`

        Requirements and Composer installation.

    ..  card:: :ref:`Configuration <configuration>`

        Settings, values pinned in the system configuration, secrets from
        environment variables, user TSconfig and access.

    ..  card:: :ref:`Usage <usage>`

        Reporting a problem, screenshots, the report history, the review
        state and retention.

    ..  card:: :ref:`Integration <integration>`

        Email markers, the webhook request, signature verification and
        ticket references.

    ..  card:: :ref:`Privacy and security <privacy-security>`

        What is collected, how reports are stored and protected, and the
        trust boundary of the webhook.

    ..  card:: :ref:`Known limitations <limitations>`

        Deliberately reduced features, browser requirements and the TYPO3
        version scope.

    ..  card:: :ref:`Upgrade <upgrade>`

        Updating the extension and the database schema.

    ..  card:: :ref:`Troubleshooting <troubleshooting>`

        Missing buttons, undelivered reports, large screenshots and database
        errors.

    ..  card:: :ref:`Get help <help>`

        Bug reports, security issues and contributing.
