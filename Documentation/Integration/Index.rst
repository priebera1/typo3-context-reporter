:navigation-title: Integration

..  _integration:

===========
Integration
===========

When the reporter clicks :guilabel:`Send report`, the report is delivered to
all enabled destinations. :guilabel:`Save and download` stores the report and
downloads it without sending it. All destinations use the same report
document (schema ``context-reporter.report.v1``).

..  _integration-download:

Download
========

JSON
    The complete report document. The screenshot is embedded as base64 in
    ``attachments[].contentBase64``.

Markdown
    A readable version for tickets and chats, without the screenshot.

JSON and Markdown downloads are recorded in the delivery history.

After sending a report, the reporter can copy a short text summary, the
Markdown version, the link to the report and the JSON document to the
clipboard; administrators can do the same in the report history. The copied
JSON has the same schema, but no ``contentBase64``: screenshots are only
included in the downloaded file.

The Markdown version escapes the title, description and other text that
users entered, so it cannot add images, links, HTML or structure to a ticket.
URLs in user text are shown as code.

..  _integration-email:

Email
=====

Emails are plain text and sent with the TYPO3 mail API and its configured
transport. The subject and the body template contain markers such as
``{report.title}``. Unknown markers are left untouched. The screenshot and the
JSON report can be attached. The header ``X-Context-Reporter-Report``
contains the report ID; test emails carry ``X-Context-Reporter-Test: 1``
instead. See :ref:`configuration-email-status` if emails do not arrive.

..  _integration-email-markers:

Markers
-------

..  list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Marker
        -   Value

    *   -   ``{report.id}``, ``{report.title}``, ``{report.description}``
        -   Report ID (for example ``CR-CR91-A84Z-DA59``), title and description

    *   -   ``{report.createdAt}``, ``{report.source}``, ``{report.url}``
        -   Creation time (UTC), entry point and backend link to the report

    *   -   ``{project.name}``, ``{project.identifier}``, ``{project.environment}``, ``{project.url}``
        -   Project settings and backend URL

    *   -   ``{reporter.name}``, ``{reporter.username}``, ``{reporter.email}``, ``{reporter.uid}``, ``{reporter.groups}``
        -   Reporter details, if shared. ``{reporter.name}`` falls back to the
            username, the UID or ``(not shared)``

    *   -   ``{page.uid}``, ``{page.title}``, ``{page.slug}``, ``{page.url}``, ``{page.backendUrl}``
        -   Page of the report

    *   -   ``{record.table}``, ``{record.tableTitle}``, ``{record.uid}``, ``{record.label}``, ``{record.type}``, ``{record.backendUrl}``
        -   Record of the report

    *   -   ``{file.uid}``, ``{file.name}``, ``{file.identifier}``, ``{file.mimeType}``, ``{file.backendUrl}``
        -   File of the report; the identifier is the combined identifier,
            for example ``1:/user_upload/logo.png``

    *   -   ``{folder.identifier}``, ``{folder.name}``, ``{storage.name}``
        -   Folder of the report or of the reported file, and its storage

    *   -   ``{site.identifier}``, ``{site.base}``
        -   Site of the page

    *   -   ``{context.summary}``
        -   One line, for example ``Page Content "Our story" [tt_content:2]
            (Text & Media) on page "About us" [2] · site main · language
            English · workspace Live · editing form``

    *   -   ``{context.subject}``, ``{context.subjectUrl}``
        -   Subject and its backend link

    *   -   ``{context.details}``
        -   All technical data as readable text

    *   -   ``{context.language}``, ``{context.workspace}``, ``{context.module}``
        -   Language, workspace and backend module

    *   -   ``{system.typo3Version}``, ``{system.phpVersion}``, ``{system.applicationContext}``
        -   System information

    *   -   ``{browser.summary}``, ``{browser.userAgent}``, ``{browser.viewport}``, ``{browser.language}``
        -   Browser information, if shared

Markers without a value are replaced with an empty string. Markers in the
subject are reduced to a single line.

..  _integration-webhook:

Webhook
=======

The webhook sends an HTTP ``POST`` request with a JSON body.

..  code-block:: http
    :caption: Request headers

    POST /your/endpoint HTTP/1.1
    Content-Type: application/json; charset=utf-8
    User-Agent: TYPO3-Context-Reporter/0.1.0
    X-Context-Reporter-Event: report.created
    X-Context-Reporter-Report: CR-CR91-A84Z-DA59
    X-Context-Reporter-Delivery: 7dd6d20a-ee21-4107-be2e-5767269320d2
    X-Context-Reporter-Attempt: 1
    X-Context-Reporter-Timestamp: 1789565415
    X-Context-Reporter-Signature: t=1789565415,v1=5b0c…
    Authorization: Bearer …

The signature header is only sent when :confval:`setting-webhook-secret` is
set. The authentication header is only sent when
:confval:`setting-webhook-authheadervalue` is set.

..  code-block:: json
    :caption: Request body (shortened)

    {
      "event": "report.created",
      "delivery": {
        "id": "7dd6d20a-ee21-4107-be2e-5767269320d2",
        "attempt": 1,
        "sentAt": "2026-09-16T13:30:15+00:00"
      },
      "report": {
        "schema": "context-reporter.report.v1",
        "id": "CR-CR91-A84Z-DA59",
        "createdAt": "2026-09-16T13:30:15+00:00",
        "source": "formEngine",
        "title": "Media field shows no files",
        "description": "I opened the content element and the image selector stays empty.",
        "summary": "Page Content \"Our story\" [tt_content:2] (Text & Media) on page \"About us\" [2] · site main · language English · workspace Live · editing form",
        "subject": {
          "type": "record",
          "table": "tt_content",
          "uid": 2,
          "label": "Our story",
          "typeLabel": "Text & Media",
          "backendUrl": "https://www.example.com/typo3/record/edit?edit%5Btt_content%5D%5B2%5D=edit"
        },
        "project": { "name": "Example", "identifier": "example", "environment": "Production", "backendUrl": "https://www.example.com/typo3/" },
        "reporter": { "uid": 3, "username": "editor" },
        "context": {
          "backend": { "route": { "identifier": "record_edit", "path": "/record/edit" }, "backendLanguage": "en" },
          "page": { "uid": 2, "pid": 1, "title": "About us", "slug": "/about-us", "doktype": 1, "doktypeLabel": "Standard", "hidden": false },
          "record": {
            "table": "tt_content",
            "tableTitle": "Page Content",
            "uid": 2,
            "pid": 2,
            "label": "Our story",
            "type": { "field": "CType", "value": "textmedia", "label": "Text & Media" },
            "languageId": 0,
            "colPos": 0,
            "hidden": false
          },
          "formEngine": { "mode": "edit", "records": [{ "table": "tt_content", "uid": 2 }] },
          "site": { "identifier": "main", "base": "https://www.example.com/", "rootPageId": 1 },
          "language": { "id": 0, "title": "English", "locale": "en-US" },
          "workspace": { "id": 0, "title": "Live" }
        },
        "system": { "typo3Version": "14.3.7", "phpVersion": "8.3.33", "applicationContext": "Production" },
        "browser": { "summary": "Chrome 148 · macOS · 1440×900", "language": "en-US" },
        "attachments": [
          {
            "type": "screenshot",
            "filename": "CR-CR91-A84Z-DA59-screenshot.png",
            "mediaType": "image/png",
            "size": 102939,
            "width": 1440,
            "height": 900,
            "sha256": "5f03…",
            "contentBase64": "iVBORw0KGgo…"
          }
        ],
        "links": { "report": "https://www.example.com/typo3/module/system/context-reports/show?report=CR-CR91-A84Z-DA59" },
        "generator": { "name": "TYPO3 Context Reporter", "package": "priebera/typo3-context-reporter", "version": "0.1.0" }
      }
    }

Notes on the report document:

*   ``source`` is ``toolbar``, ``contextMenu``, ``formEngine``,
    ``recordList``, ``pageModule`` or ``fileList``.
    ``subject.type`` is ``backend``, ``page``, ``record``, ``file`` or
    ``folder``. Files and folders have a combined ``subject.identifier``,
    for example ``1:/user_upload/logo.png``.
*   ``project``, ``reporter``, ``system`` and ``browser`` are omitted when
    there is nothing to share. ``context`` only contains the sections that
    apply, for example ``page``, ``record``, ``file``, ``folder``,
    ``storage``, ``formEngine``, ``site``, ``language``, ``workspace``,
    ``backend`` and ``recentErrors``.
*   New fields can be added in later versions. Receivers should ignore
    unknown fields.
*   Backend links contain no tokens. Users who are not logged in are asked to
    log in first.

..  _integration-webhook-test:

Test deliveries
---------------

:guilabel:`Send test webhook` in the settings posts a request with the same
headers, signature, authentication and timeout, but with the event ``test``
and without report data. Receivers should accept it (any ``2xx`` response)
and not create a ticket.

..  code-block:: json
    :caption: Test request body

    {
      "event": "test",
      "delivery": { "id": "0b8e6f58-7f55-4d86-8a1b-5d8f1e7c2a10", "attempt": 1, "sentAt": "2026-09-17T09:00:00+00:00" },
      "test": {
        "message": "Test delivery from TYPO3 Context Reporter. It does not contain a report.",
        "project": { "name": "Example", "identifier": "example", "environment": "Production" },
        "reportSchema": "context-reporter.report.v1"
      },
      "generator": { "name": "TYPO3 Context Reporter", "package": "priebera/typo3-context-reporter", "version": "0.1.0" }
    }

Check ``X-Context-Reporter-Event`` (``report.created`` or ``test``) before
processing a request.

..  _integration-webhook-signature:

Verifying the signature
-----------------------

The signature header has the form ``t=<unix timestamp>,v1=<hex digest>``.
The digest is ``HMAC-SHA256(secret, "<timestamp>.<raw request body>")``.
Always verify against the raw body, compare in constant time and reject old
timestamps.

..  code-block:: php
    :caption: PHP

    function isValidContextReporterRequest(string $body, string $header, string $secret): bool
    {
        if (!preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $header, $matches)) {
            return false;
        }
        if (abs(time() - (int)$matches[1]) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $matches[1] . '.' . $body, $secret);
        return hash_equals($expected, $matches[2]);
    }

    $valid = isValidContextReporterRequest(
        file_get_contents('php://input'),
        $_SERVER['HTTP_X_CONTEXT_REPORTER_SIGNATURE'] ?? '',
        getenv('CONTEXT_REPORTER_WEBHOOK_SECRET'),
    );

..  code-block:: javascript
    :caption: Node.js (rawBody is the unparsed body as Buffer)

    import { createHmac, timingSafeEqual } from 'node:crypto';

    function isValidContextReporterRequest(rawBody, header, secret, toleranceSeconds = 300) {
      const match = /^t=(\d{1,12}),v1=([a-f0-9]{64})$/.exec(header ?? '');
      if (!match || Math.abs(Date.now() / 1000 - Number(match[1])) > toleranceSeconds) {
        return false;
      }
      const expected = createHmac('sha256', secret).update(`${match[1]}.`).update(rawBody).digest();
      return timingSafeEqual(expected, Buffer.from(match[2], 'hex'));
    }

..  _integration-webhook-response:

Response, references and retries
--------------------------------

*   Any ``2xx`` response counts as delivered. Redirects are not followed.
*   Other responses, connection errors and timeouts count as failed. The
    beginning of the response body is stored in the delivery history, with
    URLs and configured secrets removed.
*   If the receiver creates a ticket, it can return its reference:

    ..  code-block:: json

        {"reference": "SUP-42", "url": "https://support.example.com/SUP-42"}

    The keys ``reference``, ``ticketId``, ``ticket_id``, ``issueKey``,
    ``issue_key``, ``key``, ``number`` and ``id`` are recognized as reference,
    and ``url``, ``html_url``, ``web_url``, ``link``, ``ticketUrl`` and
    ``ticket_url`` as link. They are also read from a nested ``data``,
    ``ticket``, ``issue`` or ``result`` object. The reference and the link are
    shown to the reporter and in the report history.

    Before they are stored, a reference that is a URL or contains a
    configured secret is dropped. Query parameters that look like
    credentials (for example ``token``, ``access_token``, ``api_key``,
    ``signature`` or ``password``) are removed from the link, and a link with
    user information or a configured secret is dropped. Return a plain
    ticket number and a link without credentials.
*   Deliveries are sent while the reporter waits. There is no queue and no
    automatic retry. Administrators can retry failed deliveries in the report
    history. A retry has a new ``delivery.id`` and the next ``attempt``
    number, so use the report ``id`` to detect duplicates.

Automation tools such as n8n, Make or Zapier can receive the webhook and
create issues in Jira, GitLab, GitHub, Slack or any other system.
