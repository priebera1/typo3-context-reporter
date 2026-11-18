:navigation-title: Context Reporter Hub

..  _integration-hub:

====================
Context Reporter Hub
====================

Context Reporter Hub is a hosted inbox for the reports of several TYPO3
installations. Each installation sends its reports to one *site* in the Hub.
The Hub receives the signed :ref:`webhook request <integration-webhook>`
unchanged: the same headers, signature, report document
(``context-reporter.report.v1``) and test delivery. The settings section
:guilabel:`Context Reporter Hub` only presets this request for a Hub site.
The :ref:`webhook <configuration-webhook>` stays available for other
systems, and both destinations can be used at the same time.

..  _integration-hub-connect:

Connecting a site
=================

#.  Connect a site in the Hub. The Hub shows its webhook URL, which ends in
    ``/in/s_…``, and its signing secret, which starts with ``whsec_``. The
    secret is shown only once.
#.  In TYPO3, open :guilabel:`System > Context Reports > Settings >
    Context Reporter Hub`, switch on :guilabel:`Send reports to Context
    Reporter Hub`, paste the webhook URL and the signing secret, and save.
#.  Click :guilabel:`Test connection`. The Hub checks the signature of the
    test delivery, shows the site as connected and creates nothing. The
    :guilabel:`Status` panel keeps the result of the last test for the saved
    URL and secret.
#.  Send a first report, for example with :guilabel:`Report a problem` in
    the backend toolbar. It appears in the Hub inbox.

If the Hub site has a project identifier, set the same
:confval:`setting-general-projectidentifier`, so reports of several
installations are easy to tell apart.

The settings reject values that cannot belong to a Hub site, for example the
secret pasted into the URL field. All settings are described in
:ref:`configuration-hub`.

..  _integration-hub-result:

References and links
====================

The Hub answers every accepted report with its reference and a link to the
report in the Hub inbox:

..  code-block:: json

    {"reference": "LW-152", "url": "https://hub.example.com/app/reports/LW-152"}

Unlike a generic webhook, where any ``2xx`` answer counts as delivered, a
delivery to the Hub only counts as received with this acknowledgement:

*   No answer, or an error status: the delivery failed, as for any webhook.
*   The answer was lost after the request reached the Hub (the whole request
    was sent, then no answer within the time limit or the connection closed
    while waiting; a ``2xx`` answer cut off; ``502`` or ``504`` from a proxy
    that gave up waiting for the Hub): the delivery is *not confirmed*. The Hub stores a report
    before it answers, so it may have received it. The reporter reads
    :guilabel:`Not confirmed by Context Reporter Hub (it may have arrived)`
    instead of :guilabel:`Could not be sent`, and the delivery history keeps it
    as failed with a message that starts with :guilabel:`Not confirmed:`; a
    retry with the same outcome reads :guilabel:`was not confirmed again`.
    A Hub that cannot be reached (DNS, connection refused or timed out, TLS)
    never received the report: that stays a failed delivery.
    Retrying it is safe, see :ref:`integration-hub-retries`.
*   A ``2xx`` answer without a Hub reference (empty, not JSON, cut off, larger
    than 64 KB): the delivery failed with :guilabel:`the Hub did not confirm
    the report`. Retrying it is safe, see :ref:`integration-hub-retries`.
*   A reference: the report was received. The link is shown only when it
    points into the Hub app (``/app/…``) on the trusted Hub address, that is
    :confval:`setting-hub-publicurl` or, when it is empty, the address of the
    webhook URL; otherwise only the reference is shown. Stored links are
    checked again against the current setting when the history shows them.

The reporter sees the reference after sending: the dialog reads
:guilabel:`Report received as LW-152 in Context Reporter Hub.`, lists
:guilabel:`Sent via Context Reporter Hub · LW-152` and offers
:guilabel:`Open in Context Reporter Hub`. Signing in to the Hub is needed to
open it. Administrators find the reference in the delivery history of the
report. References and links never contain secrets, see
:ref:`integration-webhook-response`. The answer to a test delivery contains no
reference; the test succeeds only with the Hub's confirmation
``{"status": "ok", …}``.

..  _integration-hub-screenshots:

Screenshots the Hub did not store
---------------------------------

The acknowledgement may also say whether the Hub stored
the screenshot and how full the storage of the Hub workspace is. Both keys are
optional; older Hubs do not send them:

..  code-block:: json

    {
        "reference": "LW-152",
        "url": "https://hub.example.com/app/reports/LW-152",
        "screenshots": {"received": 1, "stored": 0, "omitted": 1, "reasons": ["storage_full"]},
        "storage": "full"
    }

``screenshots``
    Only present when the delivery contained a screenshot. ``received``,
    ``stored`` and ``omitted`` are whole numbers of at least 0. ``reasons``
    lists why screenshots were not stored: ``storage_full`` (the storage of
    the Hub workspace is full), ``too_large``, ``unsupported``,
    ``checksum_mismatch`` (it did not arrive intact) or ``not_saved`` (the Hub
    could not save it in time).

``storage``
    ``ok``, ``nearly_full`` (at least 80 % of the workspace storage used),
    ``critical`` (at least 90 %) or ``full``.

The report itself was received: the delivery counts as successful with its
reference, and the screenshot stays with the report in TYPO3. The reporter
reads :guilabel:`Report received as LW-152 in Context Reporter Hub.` and,
separately, :guilabel:`The screenshot was not stored in Context Reporter Hub.`
with the reason; for ``storage_full``: :guilabel:`The storage of the Hub
workspace is full. Ask the developer or agency who looks after this site.`
The delivery history of the report names the screenshot that was not stored.
When ``storage`` is ``critical`` or ``full``, the delivery history and the
:ref:`Hub card <integration-hub-card>` say so; the reporter is not told.

The keys are read strictly and never decide anything else: values of another
type, negative numbers and lists instead of objects are ignored, counts are
capped at 100 and only the first 10 reasons are read, reasons this version
does not know are shown as :guilabel:`The Hub did not give a known reason.`,
and unknown storage states are ignored. Without ``omitted``, the difference of
``received`` and ``stored`` counts. Only the checked values are kept with the
delivery.

..  _integration-hub-card:

The Hub card in the report history
==================================

With a Hub connection, :guilabel:`System > Context Reports` shows a card above
the report history with what this installation knows about the connection:

*   whether delivery to the Hub is switched on, and for administrators the
    address of the Hub (scheme and host only);
*   the last delivery to the Hub with its reference, and a storage warning
    when the Hub reported its workspace storage as nearly or completely full;
*   the last failed delivery, marked as resolved when a later delivery
    succeeded;
*   the result and time of the last :guilabel:`Test connection`;
*   :guilabel:`Open Context Reporter Hub` (the Hub app on the trusted Hub
    address): for administrators, and for every user of the report history
    when :confval:`setting-hub-publicurl` is set (the address of the webhook
    URL can be an internal name);
*   for administrators :guilabel:`Hub settings` and :guilabel:`Test connection`.

The Hub is write-only for TYPO3: the installation sends reports and the signed
connection test to the webhook URL and reads nothing back. The card asks the
Hub nothing and never delays the report history; the triage state of the
reports (status, assignee, comments) is shown in the Hub itself.

Without a Hub connection, administrators see a single line that explains the
optional Hub and links to its settings; other users of the report history see
nothing.

..  _integration-hub-retries:

Retries and duplicates
======================

The Hub keeps one report per site and report ID. When a delivery failed, for
example because the secret was rotated in the Hub or the answer of the Hub was
lost, save the new secret if needed and click :guilabel:`Retry` in the
delivery history: a retry sends the same report ID, so the Hub creates the
report once and answers its reference. In the report dialog, sending again
after a lost answer (for example a dropped connection) returns the report that
was already stored; it is neither stored nor delivered twice. A report that reaches the Hub twice, for example
because the webhook also sends to the same Hub site, is stored once, and both
deliveries get the same reference. The :guilabel:`Status` panel points out a
webhook that sends to the Hub site of the Hub section.

..  _integration-hub-test:

When the connection test fails
==============================

The test shows the cause and the answer of the Hub without secrets:

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Message
        -   What to do

    *   -   :guilabel:`The Hub rejected the signature`
        -   Copy the current signing secret of the site from the Hub. The
            secret is shown once; if it is lost, create a new one in the Hub.
            If the details mention the timestamp, the clock of the TYPO3
            server is more than five minutes off.

    *   -   :guilabel:`The Hub does not know this webhook URL`
        -   Copy the webhook URL of the site from the Hub again.

    *   -   :guilabel:`The site is disconnected in the Hub`
        -   The Hub refuses deliveries for the site. Use the URL and secret of
            a connected site.

    *   -   :guilabel:`The Hub could not be reached`
        -   Check the URL and whether the TYPO3 server can reach the Hub
            (firewall, proxy, DNS). If TYPO3 reaches the Hub at another
            address than the browser, see :ref:`integration-hub-local`.

    *   -   :guilabel:`The Hub answered with a redirect`
        -   Redirects are not followed. Use the URL exactly as the Hub shows
            it, usually with ``https``.

    *   -   :guilabel:`The answer did not come from Context Reporter Hub`
        -   The address answered, but without the Hub's confirmation, for
            example with the login page of a proxy. Check that the webhook URL
            leads to the Hub.

Failed report deliveries are recorded in the delivery history with the HTTP
status code and the answer of the Hub, see :ref:`troubleshooting-webhook`.

..  _integration-hub-pin:

Pinning the connection
======================

System maintainers can pin the Hub connection in the system configuration,
like the webhook (see :ref:`privacy-webhook-boundary`). Pinned values are
read-only in the settings:

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']['hub'] = [
        'enabled' => true,
        'url' => '%env(CONTEXT_REPORTER_HUB_URL)%',
        'secret' => '%env(CONTEXT_REPORTER_HUB_SECRET)%',
        // Optional: where the browser opens the Hub, see below
        'publicUrl' => '',
        'allowInsecureHttp' => false,
    ];

..  _integration-hub-local:

When TYPO3 reaches the Hub at another address
=============================================

Usually TYPO3 and the browser reach the Hub at the same address. Then use the
webhook URL exactly as the Hub shows it and leave :guilabel:`Hub address for
links` empty.

Some installations reach the Hub at another address than the browser, for
example through an internal host name or a proxy of the hosting environment,
or from a container that reaches the host through ``host.docker.internal``:

*   Use the address that TYPO3 can reach in the webhook URL and keep the path
    ``/in/s_…`` as the Hub shows it, for example
    ``https://hub.internal.example/in/s_…``.
*   Set :guilabel:`Hub address for links` (:confval:`setting-hub-publicurl`)
    to the address the browser opens, for example ``https://hub.example.com``.
    The links the Hub returns use that address, and links are only shown on
    the trusted Hub address: without this setting, that is the address of the
    webhook URL, and only the references are shown.
*   Plain ``http`` is refused unless
    :confval:`setting-hub-allowinsecurehttp` is switched on. It is meant for a
    Hub in a local or private test environment only; never use it for a Hub
    that is reached over the internet.

..  code-block:: text
    :caption: Example: TYPO3 reaches the Hub at an internal address

    Webhook URL             https://hub.internal.example/in/s_…
    Hub address for links   https://hub.example.com
