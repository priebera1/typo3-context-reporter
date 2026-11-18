:navigation-title: Configuration

..  _configuration:

=============
Configuration
=============

Administrators configure Context Reporter in :guilabel:`System > Context
Reports`: the :guilabel:`Settings` button is at the top right of the report
list. The settings have the sections :guilabel:`General`,
:guilabel:`Privacy`, :guilabel:`Reports & storage`, :guilabel:`Email`,
:guilabel:`Context Reporter Hub` and :guilabel:`Webhook`. Only administrators
can open them.

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

Enable only the reporter and diagnostic information your organisation needs.
Report contents and, where enabled, screenshots are delivered outside this
TYPO3 installation when email, Context Reporter Hub or webhook destinations are enabled, see
:ref:`privacy-sensitive`.

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

    Sends every report by email. Requires at least one recipient. Recipients
    get a formatted email with a plain-text version for email programs that
    do not show formatting, see :confval:`setting-email-htmlversion`.

..  confval:: email.recipients
    :name: setting-email-recipients
    :type: string
    :default: (empty)

    Up to 20 email addresses, separated by commas, semicolons or line breaks.

..  confval:: email.senderAddress
    :name: setting-email-senderaddress
    :type: string
    :default: (empty)

    Sender address of the email, with a full domain (``typo3@example.com``;
    ``typo3@localhost`` or ``a@b`` are refused, mail servers reject them).
    When empty, the TYPO3 default mail sender
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

    Template of the plain-text version with
    :ref:`markers <integration-email-markers>`. It must be a :file:`.txt` file
    below :file:`Resources/Private/` of an extension, for example
    ``EXT:my_sitepackage/Resources/Private/Templates/Email/ContextReport.txt``.
    Other paths are rejected, so no other file of the installation can end up
    in an email. The HTML version is not customisable: with a custom
    template, emails are sent as plain text only and contain exactly what the
    template contains, also when :confval:`setting-email-htmlversion` is on.
    The :guilabel:`Status` panel points this out.

..  confval:: email.htmlVersion
    :name: setting-email-htmlversion
    :type: boolean
    :default: true

    :guilabel:`Send a formatted HTML version`: the email gets an HTML version
    next to the plain text, with the same content, see
    :ref:`configuration-email-content`. Switch it off when the emails are read
    by a ticket system or a parser that expects plain text; the email is then
    plain text only, as before version 0.4. The HTML version is only sent with
    the shipped :confval:`setting-email-bodytemplate`; a custom template is
    always sent as plain text only.

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

..  _configuration-email-content:

What the email contains
-----------------------

With the shipped plain-text template, both versions have the same content and
order (with a custom template, the email is plain text only and contains what
the template contains): the title; the report ID, the
:ref:`Context Reporter Hub <integration-hub>` reference when the Hub received
the report, the creation time in the time zone of the TYPO3 server (named) and
in UTC, and the reporter as the :ref:`privacy settings <configuration-privacy>`
allow (a real name that is not shared is said, for example
``editor (real name not shared)``); :guilabel:`What happened`;
:guilabel:`Where it happened`; the :guilabel:`Findings`; the links; the
attachments; the technical context; and a footer that says why the recipient
gets the email and, with :confval:`setting-email-replytoreporter`, that
replies go to the reporter.

*   :guilabel:`Open report in TYPO3` opens the report in
    :guilabel:`System > Context Reports`. The reported object has its own
    link, named after it, for example :guilabel:`Open content element in
    TYPO3`. For a file it is :guilabel:`Open containing folder in TYPO3`: the
    link opens the file list at the folder of the file.
*   :guilabel:`Open in Context Reporter Hub` and the Hub reference only appear
    after the Hub acknowledged the report. The Hub is delivered first, so the
    email can name it; when the Hub does not answer or refuses the report, the
    email is sent without it (after about the Hub's time limit of 5 seconds). Every
    destination is delivered on its own: a failure of one never stops the
    others.
*   Attachments are only mentioned when they are attached: the screenshot with
    its type, size and dimensions, the JSON file when
    :confval:`setting-email-attachjson` is on.
*   The HTML version uses tables, inline styles and system fonts, has no
    images, scripts or remote resources and no tracking, adapts to phones and
    to the dark mode of Apple Mail, and stays well below the size at which
    Gmail clips messages: a very long technical context is shown as text, or
    left to the plain-text version. Report texts are shown as text, never as
    markup; only the generated backend and Hub links are links.

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

..  _configuration-hub:

Context Reporter Hub
====================

Sends every report to a site in :ref:`Context Reporter Hub <integration-hub>`.
The Hub receives the signed webhook request; this section is independent of
the :ref:`webhook <configuration-webhook>` section.

..  confval:: hub.enabled
    :name: setting-hub-enabled
    :type: boolean
    :default: false

    Sends every report to the Hub site.

..  confval:: hub.url
    :name: setting-hub-url
    :type: string
    :default: (empty)

    The webhook URL of the site as the Hub shows it, ending in ``/in/s_…``.
    Accepts an :ref:`environment variable reference <configuration-environment>`.
    Other URLs, URLs with query or credentials and ``http`` URLs (unless
    :confval:`setting-hub-allowinsecurehttp` is enabled) are rejected. A stored
    URL is never shown again: its path identifies the site.

..  confval:: hub.secret
    :name: setting-hub-secret
    :type: string
    :default: (empty)

    The signing secret of the site, starting with ``whsec_``. Required: the
    Hub rejects unsigned deliveries. Accepts an
    :ref:`environment variable reference <configuration-environment>`.

..  confval:: hub.publicUrl
    :name: setting-hub-publicurl
    :type: string
    :default: (empty)

    :guilabel:`Hub address for links`: the address at which you open the Hub
    in the browser, for example ``https://hub.example.com``, optionally with
    the path the Hub lives under. Links to reports in the Hub are only shown
    when they point into the Hub app on this address; when it is empty, on the
    address of :confval:`setting-hub-url`. Only needed when TYPO3 reaches the
    Hub at another address than the browser, see :ref:`integration-hub-local`.
    Addresses with user name, query or fragment are rejected, ``http`` only
    with :confval:`setting-hub-allowinsecurehttp`. Accepts an
    :ref:`environment variable reference <configuration-environment>`.

..  confval:: hub.includeScreenshot
    :name: setting-hub-includescreenshot
    :type: boolean
    :default: true

    Sends the screenshot to Context Reporter Hub together with the report; the
    Hub stores it with the report. Screenshots can show sensitive backend data,
    see :ref:`privacy-sensitive`. A large screenshot extends the Hub's time
    limit for its upload (up to 30 seconds), see :ref:`configuration-hub`.

..  confval:: hub.allowInsecureHttp
    :name: setting-hub-allowinsecurehttp
    :type: boolean
    :default: false

    Allows plain ``http://`` addresses (webhook URL and Hub address for
    links), for a Hub in a local or private test environment only. Never use
    it for a Hub that is reached over the internet, see
    :ref:`integration-hub-local`.

The Hub connection sends no authentication header and waits up to 5 seconds
for the Hub, including the upload and its answer (a total time limit). A
request larger than 1 MiB (a large screenshot) gets one more second per
256 KiB for its upload, at most 30 seconds in total, so a report with a large
screenshot also reaches the Hub over a slow uplink (about 2 Mbit/s). The
reporter waits for the Hub after :guilabel:`Send report`, so a Hub that cannot
be reached, does not answer or answers too slowly fails quickly: the delivery is
recorded as failed with the cause, the email is sent without the Hub reference,
and the delivery can be retried from the history without creating the report
twice. The limit includes the upload of the screenshot: a large screenshot over
a slow connection of the TYPO3 server can exceed it, and so can a Hub that
stores the report but answers late. Such a delivery is recorded as failed;
retry it, and switch off :confval:`setting-hub-includescreenshot` if large
screenshots fail repeatedly. Like the webhook, it is configured by trusted administrators and
can be pinned in the system configuration, see :ref:`integration-hub-pin`.

..  _configuration-hub-status:

Hub status and connection test
------------------------------

The :guilabel:`Status` panel shows whether the saved URL and secret can be
used, without showing them: the URL is reduced to scheme and host, the secret
to its state. It warns when delivery is switched off, a value is missing or
does not belong to a Hub site, the URL uses ``http``, or the
:ref:`webhook <configuration-webhook>` sends to the same Hub site.

:guilabel:`Test connection` sends the test delivery of the webhook (event
``test``, see :ref:`integration-webhook-test`) to the Hub, also while delivery
to the Hub is switched off. The Hub shows the site as connected and creates
no report. The test only succeeds with the Hub's confirmation; another answer
(for example a login page of a proxy) fails with :guilabel:`The answer did not
come from Context Reporter Hub`. The panel shows the result of the last test as
long as the URL and the secret are unchanged; see :ref:`integration-hub-test` if it fails. Tests
are not added to the delivery history.

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
    keep the stored URL. A user name or password in the URL
    (``https://user:password@…``) is refused: use
    :confval:`setting-webhook-authheadervalue` for credentials.

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

:confval:`setting-webhook-url`, :confval:`setting-webhook-secret`,
:confval:`setting-webhook-authheadervalue`, :confval:`setting-hub-url` and
:confval:`setting-hub-secret` accept a value of the form
``%env(NAME)%``, both in the settings page and in the system configuration.
The value is read from the environment variable ``NAME`` when a report is
delivered, so the secret itself is not stored in the database or in a
configuration file. Settings that are entered directly are stored in
:sql:`sys_registry`; prefer environment variables for production secrets.

The resolved value is sent to the webhook endpoint or the Hub, as signing key
or header value. Any environment variable of the web server process can be referenced,
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
*   Administrators can use everything in :guilabel:`System > Context
    Reports`.
*   Other backend users can use the report history when an administrator
    grants the module: in their backend user group, :guilabel:`Access Lists >
    Modules`, select :guilabel:`Context Reports` (``system_contextreports``).
    They see all reports, open, copy and download them, mark them as resolved
    or reopen them and retry failed deliveries, and they see the Hub card.
    Grant it only to users who may see every report, including screenshots and
    reporter details.
*   The settings, test deliveries, the cleanup and deleting reports stay with
    administrators, also for users with the module. The routes check this
    again. Where reports are sent (the address of the webhook and the Hub, the
    number of email recipients) is only shown to administrators; other users
    see the result of each delivery without it.
*   Backend users without the module only create reports and download their
    own reports; the report dialog does not link to the history for them.
