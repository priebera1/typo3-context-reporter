:navigation-title: Privacy and security

..  _privacy-security:

====================
Privacy and security
====================

..  _privacy-allowlist:

Allowlist first
===============

The collectors of Context Reporter read an allowlist of metadata, described
in :ref:`introduction-context`. Everything that will be sent is shown in the
report dialog before the reporter submits it.

The collectors do not read:

*   :file:`.env` files, environment variables, database credentials or other
    configuration values
*   API keys, access tokens or SMTP credentials
*   cookies, session data, ``Authorization`` headers or any other HTTP header
*   ``$GLOBALS``, configuration dumps or database dumps
*   record field values other than the record label, for example body text,
    and no form contents; multi-line text is never used as record label
*   file contents, file metadata values or storage configuration
*   the backend log, unless :confval:`setting-privacy-recentbackenderrors` is
    enabled

Labels are data, too: page titles, record labels, file and folder names and
the names of sites, languages and workspaces are part of the report, because
they identify the object. Choose the destinations of the reports
accordingly.

What the reporter writes and what the screenshot shows is up to the
reporter. The dialog asks reporters to redact confidential information before
they send a screenshot.

Backend URLs are reduced to allowlisted parameters. CSRF tokens and return
URLs are removed in the browser and again on the server.

..  _privacy-recent-errors:

Recent backend errors
---------------------

:confval:`setting-privacy-recentbackenderrors` is off by default. When it is
enabled, reports contain up to ten error and warning entries of the reporter
from the backend log (:sql:`sys_log`) of the last 30 minutes, each shortened
to 300 characters. Log messages are written by TYPO3 and by other extensions.
They can contain record titles, file names, field values or other sensitive
diagnostic text, and Context Reporter cannot filter them reliably. Enable the
option only if everyone who receives the reports may see such text.

..  _privacy-reporter:

Reporter identity
=================

By default, only the backend user UID and username are shared. Real name,
email address and groups can be enabled separately, see
:ref:`configuration-privacy`.

..  _privacy-screenshots:

Screenshots
===========

*   Screenshots are created and edited in the browser. They are only sent to
    the TYPO3 installation and from there to the destinations you have
    configured; Context Reporter uses no external service.
*   Nothing is uploaded before the reporter clicks :guilabel:`Send report` or
    :guilabel:`Save and download`.
*   Annotations and redactions are merged into the image before upload.
    Redacted areas are covered with opaque black boxes, so the covered pixels
    are not part of the uploaded image.
*   Screen capture uses the browser's own picker and captures a single
    frame.
*   The server accepts PNG and JPEG images only and checks the file
    signature, size and dimensions.

..  _privacy-storage:

Storage
=======

Reports are stored in three tables of the TYPO3 database:

:sql:`tx_contextreporter_report`
    The report, including the complete context document as JSON, the review
    state and the delivery state.

:sql:`tx_contextreporter_attachment`
    Screenshots, as binary data (``MEDIUMBLOB`` on MariaDB and MySQL, up to
    16 MB).

:sql:`tx_contextreporter_delivery`
    The delivery history.

No files are written to :file:`fileadmin` or :file:`var`, so screenshots are
not reachable by a URL and are part of every database backup. Keep this in
mind for the size of the database and its backups, and for copies of the
production database on other systems.

*   The default retention keeps reports forever, so the database grows with
    every report. Configure :confval:`setting-reporting-retentiondays` and
    schedule the :ref:`cleanup <usage-retention>` if the history should not
    grow indefinitely.
*   A screenshot is stored in one database query. The largest screenshot
    (:confval:`setting-reporting-maxscreenshotsizekb`, at most 15 MB) must fit
    into the ``max_allowed_packet`` of MariaDB or MySQL (16 MB by default in
    MariaDB, 64 MB in MySQL 8).
*   The settings page shows how many reports, screenshots (with their size)
    and delivery attempts are stored.

..  _privacy-protection:

Protection of reports
=====================

*   The context shown in the dialog is sealed with an HMAC when the dialog
    opens. The server rejects reports whose context was changed, whose draft
    is older than six hours, or whose draft belongs to another user.
*   Reports can only reference objects the reporter can access:

    *   pages within the web mounts and page permissions of the reporter, and
        records of tables the reporter may read, on such pages;
    *   files and folders in the file storages and file mounts of the
        reporter, with read permission;
    *   file metadata (:sql:`sys_file_metadata`) and file references on the
        root page only when the reporter can access the file;
    *   in workspaces: live records and versions of the reporter's current
        workspace only. A workspace version is reported with the live UID of
        its record. Records of other workspaces are rejected, also when their
        UIDs appear in the URL of the editing form.

*   Reports are stored in the TYPO3 database. Only administrators can open
    the report history, change settings, send test deliveries, run the
    cleanup and mark reports as resolved; every one of these actions checks
    this again and requires the backend route token. Deleting a report and
    running the cleanup need a confirmation. Reporters can download their own
    reports.
*   Downloads are sent with ``Cache-Control: private, no-store``,
    ``X-Content-Type-Options: nosniff`` and a restrictive Content Security
    Policy.
*   The Markdown version of a report escapes the text that users entered, so
    it cannot add images, links, HTML or headings to a ticket.
*   A rate limit per user protects the destinations.

..  _privacy-secrets:

Secrets and error messages
==========================

*   The webhook URL, secret and authentication header can be read from
    environment variables, see :ref:`configuration-environment`. Values that
    are entered in the settings are stored in :sql:`sys_registry`.
*   Secrets are write-only in the settings: the page shows whether a value is
    configured, never the value. Stored webhook URLs are reduced to scheme
    and host.
*   Error messages are sanitized before they are stored or shown: URLs and
    configured secrets (also URL-encoded), the path and query of the webhook
    URL, and the SMTP user name, password and DSN of the TYPO3 mail
    configuration are removed.
*   A ticket reference returned by a webhook receiver is dropped when it is a
    URL or contains a configured secret. A returned link loses query
    parameters such as ``token`` or ``signature``, and is dropped when it
    contains credentials or a configured secret. The same rules apply when
    the delivery history is displayed.
*   Log entries of failed collectors and deliveries contain a sanitized,
    shortened message, the exception class and code and the file and line,
    but not the exception object or its trace.
*   The email body template must be a :file:`.txt` file below
    :file:`Resources/Private/` of an extension, so the settings cannot be
    used to send other files of the installation, such as :file:`.env`, by
    email.
*   The email status shows the mail transport without credentials.

..  _privacy-webhook-boundary:

Webhook: trusted administrators
===============================

The webhook is configured by TYPO3 administrators, and Context Reporter
trusts them with outbound requests:

*   **Destinations:** an administrator chooses the URL that receives the
    reports. Context Reporter requires HTTPS (unless
    :confval:`setting-webhook-allowinsecurehttp` is enabled) and does not
    follow redirects, but it does not check whether the host is an internal
    address. The TYPO3 server sends the request, so it can reach services of
    your internal network.
*   **Environment variables:** :confval:`setting-webhook-url`,
    :confval:`setting-webhook-secret` and
    :confval:`setting-webhook-authheadervalue` accept any environment
    variable reference. The value of the variable is sent to the configured
    endpoint, as signing key or header value. An administrator who can change
    the webhook settings can therefore send the value of an environment
    variable to an endpoint of their choice. The value is never shown in the
    backend.
*   **Requests:** reports and :guilabel:`Send test webhook` send requests
    whenever an administrator or reporter triggers them, with the configured
    timeout.

If administrators should not have these possibilities:

*   Pin the webhook settings in the system configuration, for example
    :file:`config/system/additional.php`, which only system maintainers can
    change. Pinned values are read-only in the settings, see
    :ref:`configuration-storage`:

    ..  code-block:: php
        :caption: config/system/additional.php

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']['webhook'] = [
            'enabled' => true,
            'url' => '%env(CONTEXT_REPORTER_WEBHOOK_URL)%',
            'secret' => '%env(CONTEXT_REPORTER_WEBHOOK_SECRET)%',
            'authHeaderName' => '',
            'authHeaderValue' => '',
            'allowInsecureHttp' => false,
        ];

    Keys that are not pinned remain editable. Pin at least ``enabled``,
    ``url``, ``secret``, ``authHeaderName``, ``authHeaderValue`` and
    ``allowInsecureHttp``, also when their value is empty. Pinned values
    take precedence over values that were saved in the settings before.
*   Or switch the webhook off in the system configuration
    (``['webhook']['enabled'] = false``).
*   Restrict outbound connections of the web server at the network level,
    for example with a firewall or an egress proxy, if the server must not
    reach internal services.

..  _privacy-retention:

Retention
=========

Reports are kept until they are deleted or removed by the
:ref:`cleanup <usage-retention>`. The default retention keeps reports
forever; no report is removed automatically. Deleting a report also deletes
its screenshot and delivery history in the same database transaction. Copies
that were emailed, downloaded, copied or sent to a webhook are not affected.

To report a security issue, see :ref:`help-security`.
