:navigation-title: n8n, GitLab and Jira

..  _integration-n8n:

============================
n8n: GitLab and Jira issues
============================

The repository contains an n8n workflow that receives the Context Reporter
webhook, verifies its signature and creates a GitLab issue. The reporter sees
the issue number in the dialog, for example
:guilabel:`Sent via Webhook · support/website#12`, and administrators find it,
with a link, in the delivery history of the report.

Workflow file
    :file:`Documentation/Integration/N8n/context-reporter-gitlab.json`
    (`view on GitHub <https://github.com/priebera1/typo3-context-reporter/blob/main/Documentation/Integration/N8n/context-reporter-gitlab.json>`__,
    `raw file for "Import from URL" <https://raw.githubusercontent.com/priebera1/typo3-context-reporter/main/Documentation/Integration/N8n/context-reporter-gitlab.json>`__)

Requirements
    n8n 2 (tested with n8n 2.41), a GitLab project and an access token with
    the scope ``api`` of a user who may create issues in it (tested with
    GitLab CE 19.4), and the Context Reporter webhook with a
    :confval:`secret <setting-webhook-secret>`.

The workflow only uses the webhook described in :ref:`integration-webhook`.
The extension itself has no GitLab or Jira connector.

..  _integration-n8n-nodes:

What the workflow does
======================

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Node
        -   Purpose

    *   -   :guilabel:`Context Reporter webhook`
        -   Receives ``POST /webhook/context-reporter`` with the unparsed
            body (option :guilabel:`Raw Body`).

    *   -   :guilabel:`Read signature`, :guilabel:`HMAC-SHA256`,
            :guilabel:`Check signature`
        -   Compute ``HMAC-SHA256(secret, "<timestamp>.<raw body>")`` with
            the secret of an n8n Crypto credential, compare it with the
            ``X-Context-Reporter-Signature`` header in constant time and
            reject signatures that are older or newer than five minutes.

    *   -   :guilabel:`Route`
        -   Rejected requests get ``401``. The event ``test`` of
            :guilabel:`Send test webhook` gets ``200`` without creating an
            issue, ``report.created`` continues, other events get ``200``.

    *   -   :guilabel:`GitLab settings`
        -   The GitLab project and the label of the issues.

    *   -   :guilabel:`Build issue`
        -   Builds title and description: the reporter's text, the TYPO3
            context as a table and links to the report, the object in the
            TYPO3 backend and the frontend page. Everything people typed or
            named is shown as code, so it cannot mention users or add links,
            images or HTML to the issue.

    *   -   :guilabel:`Find existing issue`, :guilabel:`Issue exists?`
        -   Look for an issue of the same report ID. A repeated delivery, for
            example a manual retry after a timeout, returns the existing
            issue instead of creating a second one.

    *   -   :guilabel:`Create issue`
        -   Creates the issue and answers ``201`` with
            ``{"reference": "support/website#12", "url": "https://…"}``,
            which Context Reporter shows as ticket reference.

    *   -   :guilabel:`Respond: GitLab error`
        -   Answers ``502`` with a generic message when GitLab fails and
            marks the execution as failed, so n8n keeps it for
            troubleshooting. Retry the delivery in the report history once
            the problem is fixed.

The screenshot is not uploaded to GitLab: the issue links to the report in
TYPO3, where administrators see it.

..  _integration-n8n-setup:

Setting it up
=============

#.  In n8n, create a workflow and choose :guilabel:`Import from URL` (paste
    the raw file URL above) or :guilabel:`Import from File`.
#.  Create a :guilabel:`Crypto` credential, enter the webhook secret of
    Context Reporter as :guilabel:`HMAC Secret` and select it in the node
    :guilabel:`HMAC-SHA256`. Use a long random value, for example the output
    of ``openssl rand -hex 32``.
#.  Create a :guilabel:`GitLab API` credential with the URL of your GitLab
    server and the access token, and select it in the nodes
    :guilabel:`Find existing issue` and :guilabel:`Create issue`.
#.  Open :guilabel:`GitLab settings` and enter the group or user
    (``gitlabNamespace``, subgroups separated by ``/``), the project
    (``gitlabProject``) and the label for the issues (``issueLabel``).
#.  Publish the workflow and copy the production URL of the webhook node,
    for example ``https://n8n.example.com/webhook/context-reporter``. The test
    URL (``/webhook-test/…``) only works while the editor listens.
#.  In TYPO3, open :guilabel:`System > Context Reports > Settings >
    Webhook`, switch the webhook on and enter the production URL and the
    same secret. Prefer environment variables for both, see
    :ref:`configuration-environment`.
#.  Click :guilabel:`Send test webhook`. The status must read that the
    endpoint accepted the test webhook.
#.  Send a first report. The dialog shows the issue reference.

No secret is stored in the workflow file: the HMAC secret and the GitLab
token live in n8n credentials. The workflow needs no environment variables,
which n8n 2 blocks in nodes by default.

..  _integration-n8n-data:

Executions and data
===================

*   The workflow does not save successful executions. Failed executions are
    kept for troubleshooting and contain the request, that is the report
    including the screenshot. Restrict access to n8n and let n8n prune old
    executions (``EXECUTIONS_DATA_MAX_AGE``).
*   n8n accepts requests up to 16 MB by default
    (``N8N_PAYLOAD_SIZE_MAX``). The workflow does not use the screenshot, so
    you can switch off :confval:`setting-webhook-includescreenshot` to keep
    requests and failed executions small.
*   The signature is verified against the raw body. Keep the option
    :guilabel:`Raw Body` of the webhook node switched on, and make sure that
    no proxy changes the body.

..  _integration-n8n-troubleshooting:

Troubleshooting
===============

The delivery history of the report shows the status code and the beginning
of the response.

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   Response
        -   Cause

    *   -   ``401`` "Rejected: missing or malformed signature"
        -   No secret is configured in TYPO3, so the request is not signed,
            or a proxy removes the ``X-Context-Reporter-Signature`` header.

    *   -   ``401`` "Rejected: invalid signature"
        -   The secret in TYPO3 and the Crypto credential differ, or the
            body was changed on the way.

    *   -   ``401`` "Rejected: signature expired"
        -   The clocks of the TYPO3 and the n8n server differ by more than
            five minutes.

    *   -   ``401`` "Rejected: empty body"
        -   The option :guilabel:`Raw Body` of the webhook node is switched
            off.

    *   -   ``404``
        -   The workflow is not published, or TYPO3 uses the test URL.

    *   -   ``413`` or a closed connection
        -   The request is larger than ``N8N_PAYLOAD_SIZE_MAX``. Switch off
            :confval:`setting-webhook-includescreenshot` or raise the limit.

    *   -   ``502`` "The GitLab issue could not be created"
        -   Open the failed execution in n8n. Typical causes are an expired
            token, a missing scope or role, a wrong project in
            :guilabel:`GitLab settings`, or GitLab is not reachable from n8n.

..  _integration-n8n-jira:

Jira
====

Keep the nodes up to :guilabel:`Build issue` and replace the GitLab nodes
with nodes of the :guilabel:`Jira Software` integration:

#.  Create a :guilabel:`Jira Software` credential for your Jira site.
#.  Replace :guilabel:`Find existing issue` with :guilabel:`Issue > Get
    Many`, limit 1, the node setting :guilabel:`Always Output Data`
    switched on, and this :guilabel:`JQL` option (``SUP`` stands for your
    project key):

    ..  code-block:: text

        project = SUP AND labels = "{{ $('Build issue').first().json.reportId }}"

#.  Replace the condition of :guilabel:`Issue exists?` with
    ``{{ $json.key }}`` :guilabel:`String > exists`.
#.  Replace :guilabel:`Create issue` with :guilabel:`Issue > Create`:
    project and issue type of your choice, summary
    ``{{ $('Build issue').first().json.title }}``, and in the additional
    fields the description ``{{ $('Build issue').first().json.plainText }}``
    and, as :guilabel:`Label Names or IDs` expression,
    ``{{ ['context-reporter', $('Build issue').first().json.reportId] }}``.
    The report ID as label is what the search above finds. The field
    ``plainText`` is the description without Markdown, which Jira does not
    render.
#.  Change the response body of :guilabel:`Respond: existing issue` and
    :guilabel:`Respond: issue created` to

    ..  code-block:: text

        {{ JSON.stringify({ reference: $json.key, url: 'https://your-site.atlassian.net/browse/' + $json.key }) }}

    Jira answers with the issue key and an API URL; the link for people has
    to be built from the key.
#.  Adjust the message of :guilabel:`Respond: GitLab error`.

Try the result with :guilabel:`Send test webhook` and a report on a test
project before you use it for real reports.

For other systems, return the reference and the link with one of the keys
listed in :ref:`integration-webhook-response`.
