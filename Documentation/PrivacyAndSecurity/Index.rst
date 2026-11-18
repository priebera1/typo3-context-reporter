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
*   record field values other than the record label and the settings
    described below (visibility, placement, edit locks, creation and last
    change), for example body text, and no form contents; multi-line text is
    never used as record label
*   site settings, error handling, base variants or other site configuration
    beyond the site identifier, base, root page and the languages
*   file contents, file metadata values or storage configuration
*   the backend log, unless :confval:`setting-privacy-recentbackenderrors` is
    enabled

Labels are data, too: page titles, record labels, file and folder names,
frontend user group titles and the names of sites, languages and workspaces
are part of the report, because they identify the object. Choose the
destinations of the reports accordingly.

What the reporter writes and what the screenshot shows is up to the
reporter, see :ref:`privacy-sensitive`.

Backend URLs are reduced to allowlisted parameters. CSRF tokens and return
URLs are removed in the browser and again on the server.

..  _privacy-sensitive:

Sensitive information in reports
================================

Context Reporter is meant for reporting technical problems, not for passing
on secrets. It does not detect secrets in the title, the description or the
screenshot and does not redact anything automatically.

Reporters should never include:

*   passwords
*   API keys
*   access tokens and refresh tokens
*   private keys
*   recovery codes
*   database credentials
*   SMTP credentials
*   authentication cookies
*   other secrets or credentials

Before sending a report:

*   Review the title and the description.
*   Review the visible backend screen in the screenshot. Cover sensitive areas
    with the :guilabel:`Redact` tool, which paints opaque boxes into the image
    (only the areas the reporter covers are redacted), or remove the
    screenshot when sensitive data is visible.
*   Open :guilabel:`Review the technical data that will be sent` to see
    everything that is stored and sent.

The report dialog says this above the technical data (:guilabel:`Check for
sensitive information before sending`) and next to the screenshot. Depending
on the :ref:`privacy settings <configuration-privacy>`, a report also
contains:

*   TYPO3 page, record and file identifiers and labels
*   URLs: backend links, the website address and the backend route
*   the reporter: by default the user ID and the username, where enabled the
    real name, the email address and the backend groups
*   browser and environment information (TYPO3, PHP, database platform,
    server operating system, and where enabled browser details)
*   the :guilabel:`Findings` and the diagnostic context they are based on
*   where enabled, recent backend errors of the reporter

A report is stored in the TYPO3 database. The destinations that are
configured send it on:

*   **Email** to the configured recipients, with the screenshot when
    :confval:`setting-email-attachscreenshot` is on and the JSON file when
    :confval:`setting-email-attachjson` is on.
*   **Context Reporter Hub** to the connected Hub site, with the screenshot
    when :confval:`setting-hub-includescreenshot` is on.
*   **Webhook** to the configured endpoint, with the screenshot when
    :confval:`setting-webhook-includescreenshot` is on.

Administrators should configure only destinations they trust and enable only
the reporter and diagnostic information their organisation needs.

The signature of Hub deliveries and of webhook deliveries with a signing
secret (HMAC-SHA256) proves to the receiver that a delivery comes from this TYPO3
installation and was not changed, so the receiver can reject forged submissions
(the Hub always checks it). It does
not encrypt or hide the content of a report and does not make secrets safe
to include in it.

..  _privacy-visibility:

Visibility settings
-------------------

For pages and records, reports contain the settings that decide whether
TYPO3 shows them to website visitors, see :ref:`usage-visibility`:

*   hidden, start and end time and the frontend user groups (with their
    titles, at most ten groups of 100 characters each) of the object, its page,
    parent pages and translations, and whether a page is hidden in menus;
    TYPO3 shows these values to the reporter in the page tree and the Page
    module anyway,
*   :guilabel:`Extend to subpages` of parent pages only if the reporter may
    edit that field (:guilabel:`Allowed excludefields`; administrators may);
    otherwise inherited restrictions are left out of the report,
*   :guilabel:`Translation behaviour` of pages only if the reporter may edit
    that field,
*   parent pages only up to the first page the reporter cannot access; pages
    above it are not read, and the report only notes that they were not
    checked,
*   translations only in the languages the reporter may use, and only the
    reporter's current workspace.

No other field values are read. Like all context, the settings are shown in
the dialog and sealed before the report is sent.

..  _privacy-file-checks:

File checks
-----------

For a reported file and for the file references of a reported page or record,
reports contain the file problems TYPO3 knows about, see
:ref:`usage-file-checks`:

*   the UID and name of a referenced file, only for files with a problem and
    only if the reporter may read the file (file storages, file mounts and
    file permissions of their groups); no paths, storage configuration or
    file contents,
*   files the reporter may not read only as a count (``notChecked``),
    without name, UID or storage; a reported file reference, which TYPO3
    labels with the name of its file, then has no label,
*   references only if the reporter may list file references
    (:guilabel:`Tables (listing)`) and edit the field, and only in the
    reporter's current workspace.

Like all context, the checks are shown in the dialog and sealed before the
report is sent.

..  _privacy-website-address:

Website address
---------------

For the website address, see :ref:`usage-website-address`, reports contain
the address TYPO3 builds for the page and codes for the facts behind it. A
missing translation is only looked up in languages the reporter may use, and
the fallback languages of a language are only named if the reporter may use
them (otherwise only their ID). The site configuration itself (settings,
base variants, error handling, route enhancers) is not read into the report.

..  _privacy-placement:

Placement
---------

For pages and content elements, see :ref:`usage-placement`:

*   the column of a content element and the column names of the backend
    layout, which the Page module shows to the reporter anyway,
*   the backend layout of the page and the parent page it is set on, only if
    the reporter may edit both backend layout fields (:guilabel:`Allowed
    excludefields`), and only through parent pages the reporter may access,
*   :guilabel:`Show content from page`: the UID of the page, as the Page
    module shows it to everyone who opens the page, with its title only if
    the reporter may access it; pages that show the content of the page are
    only named if the reporter may access them, otherwise counted.

..  _privacy-file-usage:

File usage
----------

For a reported file, see :ref:`usage-file-usage`, reports name the records
and fields that refer to the file only if the reporter may access the record
(table and page permissions, current workspace) and edit the field. All other
usages are only counted, without table, UID or title. Records of backend and
frontend users and groups are never named, also not for administrators.

..  _privacy-permissions:

Permission facts
----------------

For editors, reports contain their own permissions for the reported object,
see :ref:`usage-permissions`: whether they may modify the table, their
combined page permissions, edit locks, whether they may use the language,
the record type and the page type, which fields of the form are not available
to them, and their file permissions. The report does not say where a
permission comes from: page owners and groups, the reporter's groups, other
users, workspace members and stage assignments are not collected. Reports of
administrators contain no permission facts.

..  _privacy-times:

Times
-----

Reports contain the creation time and the time of the last change of the
reported page or record, if TYPO3 keeps them for the table, and the time zone
of the server. They do not say who created or changed the record.

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

*   Reports are stored in the TYPO3 database. Administrators, and backend
    users whose group grants the module :guilabel:`Context Reports`
    (:ref:`configuration-access`), can open the report history, mark reports
    as resolved and retry deliveries. Only administrators can change settings,
    send test deliveries, run the cleanup and delete reports. Every one of
    these actions checks this again and requires the backend route token. Deleting a report and
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

*   The webhook URL, secret and authentication header and the Hub webhook
    URL and secret can be read from environment variables, see
    :ref:`configuration-environment`. Values that are entered in the settings
    are stored in :sql:`sys_registry`.
*   Secrets are write-only in the settings: the page shows whether a value is
    configured, never the value. Stored webhook and Hub URLs are reduced to
    scheme and host. The result of the last Hub connection test is stored
    with a keyed fingerprint of the URL and secret, not with the values.
*   Error messages are sanitized before they are stored or shown: URLs and
    configured secrets (also URL-encoded), the path and query of the webhook
    URL, the site token of a Hub webhook URL (``s_…``, also when it appears
    on its own), and the SMTP user name, password and DSN of the TYPO3 mail
    configuration are removed.
*   Where reports are sent (the address of the webhook and the Hub, the
    number of email recipients) is only shown to administrators, also in the
    report history that editors can be granted.
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
*   The HTML version of the report email contains the same data as the
    shipped plain-text template, and it is only sent with that template: with
    a custom template the email is plain text only, so it never contains more
    than the template. The HTML version loads nothing: no images, fonts,
    scripts or other remote
    resources, and no tracking. Report texts are escaped; only generated
    backend and Hub links (http and https) are links, and a Hub link only on
    the trusted Hub address.

..  _privacy-hub-overview:

Nothing is read back from the Hub
=================================

The connection to Context Reporter Hub is write-only: TYPO3 sends reports and
the signed connection test, and reads nothing from the Hub. The
:ref:`Hub card <integration-hub-card>` of :guilabel:`System > Context Reports`
shows only what is stored in this installation (the last deliveries and the
last connection test). Links into the Hub come from the Hub's answer to a
delivery and are only kept when they point into the Hub app on the trusted Hub
address (:confval:`setting-hub-publicurl`, otherwise the address of the
webhook URL), without credentials, query or fragment.

The :ref:`update check <privacy-update-check>` is separate from the Hub
connection: a public request to ``hub.priebera.sk`` for the latest release
number, without the webhook URL, the secret or any data about the installation.
It works the same with or without a Hub connection.

..  _privacy-webhook-boundary:

Webhook: trusted administrators
===============================

The webhook and the :ref:`Context Reporter Hub <integration-hub>` connection
are configured by TYPO3 administrators, and Context Reporter trusts them with
outbound requests. The Hub connection only accepts URLs whose path ends in
``/in/s_…`` and no authentication header, but any host; the following applies
to it as well:

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
*   Pin or switch off the Hub connection the same way, with the keys
    ``enabled``, ``url``, ``secret`` and ``allowInsecureHttp`` of
    ``['hub']``, see :ref:`integration-hub-pin`.
*   Restrict outbound connections of the web server at the network level,
    for example with a firewall or an egress proxy, if the server must not
    reach internal services.

..  _privacy-update-check:

Update check
============

For the :ref:`new version notice <upgrade-notice>`, the web server sends a plain
``GET`` request to
``https://hub.priebera.sk/api/extension/v1/releases/latest``. It needs no Hub
account or connection and contains nothing about the installation: no
installation ID, host name, TYPO3 or PHP version, user or report data. The
endpoint answers the latest stable version, its release date and a link to its
release notes; the extension compares the versions itself.

*   The request is only made while an administrator opens
    :guilabel:`System > Context Reports`, at most every 12 hours (after a
    failure at most every hour). The answer is kept in the TYPO3 registry.
*   It has a total time limit of 2 seconds, follows no redirects and reads at
    most 16 KB. An unreachable endpoint, an error or an unexpected answer
    simply means that no notice is shown.
*   The answer is checked: only a stable version number is accepted, and the
    link is only used when it is an ``https`` address of GitHub, the TYPO3
    Extension Repository, Packagist, docs.typo3.org or priebera.sk; otherwise
    the notice links to the extension's page in the TYPO3 Extension Repository.
*   The address cannot be changed in the backend. To switch the check off, set
    the environment variable ``CONTEXT_REPORTER_RELEASE_CHECK_URL=off`` for the
    web server. The same variable can name another endpoint for staging or
    tests (``https``, or ``http`` on a local development host only).

..  _privacy-retention:

Retention
=========

Reports are kept until they are deleted or removed by the
:ref:`cleanup <usage-retention>`. The default retention keeps reports
forever; no report is removed automatically. Deleting a report also deletes
its screenshot and delivery history in the same database transaction. Copies
that were emailed, downloaded, copied or sent to a webhook are not affected.

To report a security issue, see :ref:`help-security`.
