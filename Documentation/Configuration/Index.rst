:navigation-title: Configuration

..  _configuration:

=============
Configuration
=============

Administrators configure Context Reporter in :guilabel:`System > Context
Reports`: the :guilabel:`Settings` button is at the top right of the report
list. The settings have the sections :guilabel:`General`,
:guilabel:`Privacy`, :guilabel:`Reports & storage`, :guilabel:`Email` and
:guilabel:`Webhook`. Only administrators can open them.

..  _configuration-storage:

Where settings are stored
=========================

The settings of the module are stored in the TYPO3 registry (table
:sql:`sys_registry`, namespace ``tx_contextreporter``), not in
:file:`config/system/settings.php`. Each section is saved on its own; the page
shows when and by whom the settings were last saved.

Values in ``$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']``
take precedence over the module. They are shown read-only on the settings
page. Use this to pin values per environment, for example to switch off
deliveries on a staging system that runs on a copy of the production
database:

..  code-block:: php
    :caption: config/system/additional.php

    if (!\TYPO3\CMS\Core\Core\Environment::getContext()->isProduction()) {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']['email']['enabled'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']['webhook']['enabled'] = false;
    }

The keys are the setting names below, grouped by section, for example
``['reporting']['retentionDays']``. Each key is pinned on its own; keys that
are not set in the system configuration stay editable. The extension has no
:guilabel:`Extension Configuration` form.

..  _configuration-general:

General
=======

..  confval:: general.enabled
    :name: setting-general-enabled
    :type: boolean
    :default: true

    Enables reporting for backend users. It can be disabled for individual
    users or groups with :ref:`user TSconfig <configuration-tsconfig>`.
    Stored reports remain available when reporting is disabled.

..  confval:: general.projectName
    :name: setting-general-projectname
    :type: string
    :default: site name

    Shown in reports, email subjects and webhook payloads. When empty, the
    TYPO3 site name (``$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']``) is
    used.

..  confval:: general.projectIdentifier
    :name: setting-general-projectidentifier
    :type: string
    :default: (empty)

    Optional stable key, for example ``customer-portal``, that helps receiving
    systems to route reports. Up to 64 letters, digits, dots, hyphens and
    underscores.

..  confval:: general.environment
    :name: setting-general-environment
    :type: string
    :default: application context

    ``Production``, ``Staging``, ``Development``, ``Testing`` or a custom
    name of up to 50 characters. When empty, the TYPO3 application context is
    used.

..  _configuration-privacy:

Privacy
=======

The :guilabel:`Privacy` section also lists what every report contains and
what it never contains, see :ref:`privacy-allowlist`.

..  confval:: privacy.reporterUid
    :name: setting-privacy-reporteruid
    :type: boolean
    :default: true

    Share the backend user UID of the reporter. The report history uses it to
    show who reported a problem.

..  confval:: privacy.reporterUsername
    :name: setting-privacy-reporterusername
    :type: boolean
    :default: true

    Share the username of the reporter.

..  confval:: privacy.reporterRealName
    :name: setting-privacy-reporterrealname
    :type: boolean
    :default: false

    Share the real name of the reporter.

..  confval:: privacy.reporterEmail
    :name: setting-privacy-reporteremail
    :type: boolean
    :default: false

    Share the email address of the reporter. Required for
    :confval:`setting-email-replytoreporter`.

..  confval:: privacy.reporterGroups
    :name: setting-privacy-reportergroups
    :type: boolean
    :default: false

    Share the backend user groups (UID and title) and the administrator flag.

..  confval:: privacy.browserDetails
    :name: setting-privacy-browserdetails
    :type: boolean
    :default: true

    Share browser and operating system, user agent, language, time zone,
    window and screen size, pixel ratio, color scheme and the reduced motion
    preference.

..  confval:: privacy.recentBackendErrors
    :name: setting-privacy-recentbackenderrors
    :type: boolean
    :default: false

    Adds up to ten error and warning entries of the reporter from the backend
    log (:sql:`sys_log`) of the last 30 minutes, shortened. Log messages can
    contain record titles, file names or other sensitive diagnostic text,
    see :ref:`privacy-recent-errors`. Enable this only if everyone who
    receives reports may see such text.

..  _configuration-reporting:

Reports and storage
===================

..  confval:: reporting.maxScreenshotSizeKb
    :name: setting-reporting-maxscreenshotsizekb
    :type: integer
    :default: 5120

    Maximum size of a screenshot in KB (100 to 15360). Larger screenshots are
    compressed in the browser, first as PNG, then as JPEG with a lower
    resolution. Screenshots are stored in the database; the limit must stay
    below the ``max_allowed_packet`` of MariaDB or MySQL, see
    :ref:`privacy-storage`.

..  confval:: reporting.maxReportsPerUserPerHour
    :name: setting-reporting-maxreportsperuserperhour
    :type: integer
    :default: 20

    Protects the destinations against floods (0 to 1000). ``0`` disables the
    limit.

..  confval:: reporting.retentionDays
    :name: setting-reporting-retentiondays
    :type: integer
    :default: 0 (keep forever)

    How long reports, their screenshots and delivery history are kept:
    forever (``0``), 30, 90, 180 or 365 days, or a custom number of days
    (1 to 3650). Reports are only removed when an administrator runs the
    :ref:`cleanup <usage-retention>`; changing the setting removes nothing.

The section also shows how many reports, screenshots (with their size) and
delivery attempts are stored, and a preview of the cleanup. With the default
retention, the history grows with every report.

..  _configuration-email:

Email
=====

Emails are sent with the mail transport of TYPO3
(``$GLOBALS['TYPO3_CONF_VARS']['MAIL']``, :guilabel:`Admin Tools > Settings >
Configure Installation-Wide Options`). Context Reporter has no SMTP settings
of its own.

..  confval:: email.enabled
    :name: setting-email-enabled
    :type: boolean
    :default: false

    Sends every report by email. Requires at least one recipient.

..  confval:: email.recipients
    :name: setting-email-recipients
    :type: string
    :default: (empty)

    Up to 20 email addresses, separated by commas, semicolons or line breaks.

..  confval:: email.senderAddress
    :name: setting-email-senderaddress
    :type: string
    :default: (empty)

    Sender address of the email. When empty, the TYPO3 default mail sender
    (``$GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']``) is
    used.

..  confval:: email.senderName
    :name: setting-email-sendername
    :type: string
    :default: (empty)

    Sender name, used together with :confval:`setting-email-senderaddress`.

..  confval:: email.subject
    :name: setting-email-subject
    :type: string
    :default: [{project.name}] {report.title} ({report.id})

    Subject with :ref:`markers <integration-email-markers>`.

..  confval:: email.bodyTemplate
    :name: setting-email-bodytemplate
    :type: string
    :default: EXT:context_reporter/Resources/Private/Templates/Email/Report.txt

    Plain text template with :ref:`markers <integration-email-markers>`. It
    must be a :file:`.txt` file below :file:`Resources/Private/` of an
    extension, for example
    ``EXT:my_sitepackage/Resources/Private/Templates/Email/ContextReport.txt``.
    Other paths are rejected, so no other file of the installation can end up
    in an email.

..  confval:: email.attachScreenshot
    :name: setting-email-attachscreenshot
    :type: boolean
    :default: true

    Attaches the screenshot.

..  confval:: email.attachJson
    :name: setting-email-attachjson
    :type: boolean
    :default: true

    Attaches the report as JSON file, without the screenshot.

..  confval:: email.replyToReporter
    :name: setting-email-replytoreporter
    :type: boolean
    :default: false

    Uses the reporter as Reply-To address. Only works when
    :confval:`setting-privacy-reporteremail` is enabled.

..  _configuration-email-status:

Email status and test email
---------------------------

Next to the form, the :guilabel:`Status` panel explains whether reports can be
sent by email with the saved settings: whether delivery is switched on, the
recipients, the sender that is used, the TYPO3 mail transport (host and port,
sendmail binary or DSN scheme and host; credentials are never shown) and the
attachments. It warns when

*   email delivery is switched off or no recipient is configured,
*   the TYPO3 transport is ``null`` (emails are discarded) or ``mbox``
    (emails are written to a file),
*   emails are spooled and only sent by ``vendor/bin/typo3 mailer:spool:send``,
*   the transport points to a local mail catcher such as Mailpit, for example
    in DDEV projects, so emails do not reach real mailboxes,
*   no valid sender address is configured.

:guilabel:`Send test email` sends a short message without report data to the
saved recipients, also while email delivery is switched off. Transport errors
are shown without credentials.

..  tip::

    If reports do not arrive by email, check the status panel first: in most
    cases email delivery is switched off, no recipient is saved, or the
    TYPO3 mail transport is a local mail catcher or a spool.

..  _configuration-webhook:

Webhook
=======

..  confval:: webhook.enabled
    :name: setting-webhook-enabled
    :type: boolean
    :default: false

    Sends every report to the webhook endpoint.

..  confval:: webhook.url
    :name: setting-webhook-url
    :type: string
    :default: (empty)

    HTTPS endpoint that receives a JSON ``POST`` request. Accepts an
    :ref:`environment variable reference <configuration-environment>`. Webhook
    URLs often contain access tokens, so a stored URL is never shown again:
    the settings page only shows scheme and host. Leave the field empty to
    keep the stored URL.

..  confval:: webhook.secret
    :name: setting-webhook-secret
    :type: string
    :default: (empty)

    Signs each request with HMAC-SHA256, see
    :ref:`integration-webhook-signature`. Accepts an
    :ref:`environment variable reference <configuration-environment>`.

..  confval:: webhook.authHeaderName
    :name: setting-webhook-authheadername
    :type: string
    :default: Authorization

    Name of an additional authentication header, for example ``X-Api-Key``.

..  confval:: webhook.authHeaderValue
    :name: setting-webhook-authheadervalue
    :type: string
    :default: (empty)

    Value of the authentication header, for example ``Bearer <token>``. The
    header is only sent when a value is set. Accepts an
    :ref:`environment variable reference <configuration-environment>`.

..  confval:: webhook.includeScreenshot
    :name: setting-webhook-includescreenshot
    :type: boolean
    :default: true

    Embeds the screenshot as base64 in the request.

..  confval:: webhook.timeout
    :name: setting-webhook-timeout
    :type: integer
    :default: 10

    Timeout in seconds (1 to 30). The reporter waits for the delivery.

..  confval:: webhook.allowInsecureHttp
    :name: setting-webhook-allowinsecurehttp
    :type: boolean
    :default: false

    Allows plain ``http://`` URLs. Only for local development.

The webhook is configured by trusted administrators: the endpoint can be any
host the TYPO3 server can reach. See :ref:`privacy-webhook-boundary` for the
consequences and how to pin the webhook settings.

The secret fields are write-only: the settings page shows whether a value is
configured, configured via an environment variable, or whether the
environment variable is missing, but never the value. Leave a field empty to
keep the stored value, or tick :guilabel:`Remove the stored value`.

:guilabel:`Send test webhook` posts the event ``test`` with the saved
settings, also while webhook delivery is switched off, see
:ref:`integration-webhook-test`. Test deliveries are not added to the
delivery history.

..  _configuration-environment:

Secrets from environment variables
==================================

:confval:`setting-webhook-url`, :confval:`setting-webhook-secret` and
:confval:`setting-webhook-authheadervalue` accept a value of the form
``%env(NAME)%``, both in the settings page and in the system configuration.
The value is read from the environment variable ``NAME`` when a report is
delivered, so the secret itself is not stored in the database or in a
configuration file. Settings that are entered directly are stored in
:sql:`sys_registry`; prefer environment variables for production secrets.

The resolved value is sent to the webhook endpoint, as signing key or header
value. Any environment variable of the web server process can be referenced,
so only reference variables that are meant for the webhook, and see
:ref:`privacy-webhook-boundary`.

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']['webhook']['secret']
        = '%env(CONTEXT_REPORTER_WEBHOOK_SECRET)%';

Secrets are never shown in the backend, and they are removed from stored
error messages.

..  _configuration-tsconfig:

User TSconfig
=============

Disable reporting for a user or group:

..  code-block:: typoscript
    :caption: User TSconfig

    options.contextReporter.enable = 0

The toolbar button, the context menu item and the editing form button are
removed, and the server rejects reports from this user.

Hide the context menu item for a table (standard TYPO3 option). Files and
folders use the tables ``sys_file`` and ``sys_file_storage``:

..  code-block:: typoscript
    :caption: User TSconfig

    options.contextMenu.table.tt_content.disableItems = contextReporterReport
    options.contextMenu.table.sys_file.disableItems = contextReporterReport

The list view of the file list shows the actions of
``options.file_list.primaryActions`` as buttons and all other actions in the
:guilabel:`More options` menu. The extension ships this user TSconfig, which is
the TYPO3 default plus the report action:

..  code-block:: typoscript
    :caption: EXT:context_reporter/Configuration/user.tsconfig

    options.file_list.primaryActions = view, metadata, translations, delete, contextReporterReport

If your project defines its own list, add ``contextReporterReport`` to it to
keep the action visible.

..  _configuration-access:

Access
======

*   Every backend user can create reports, unless disabled with TSconfig.
*   A report can only reference pages and records that the reporter can
    access (web mounts, page permissions and table permissions), and files
    and folders in the reporter's file storages and file mounts, with read
    permission. File metadata can only be reported for accessible files. In
    a workspace, only live records and versions of the current workspace can
    be reported. See :ref:`privacy-protection`.
*   The :guilabel:`System > Context Reports` module, including the settings,
    test deliveries, the cleanup and the review state, is available to
    administrators only.
*   Reporters can download their own reports.
