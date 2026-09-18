# Contributing to TYPO3 Context Reporter

Thank you for helping to improve Context Reporter.

## Before you start

- Search the existing [issues](https://github.com/priebera1/typo3-context-reporter/issues)
  to avoid duplicates.
- Security issues: see [SECURITY.md](SECURITY.md). Do not open a public issue.
- For larger changes, open an issue first and describe the problem.

## Scope

Context Reporter answers one question: *which TYPO3 object was the user
working with when the problem happened?*

> The screenshot shows what the user sees. The TYPO3 context tells the
> developer what the problem actually belongs to.

Out of scope: ticket workflows (boards, assignments, priorities, comments,
SLAs, time tracking) and native connectors for individual ticket systems. Use
the webhook for integrations.

## Rules for new context data

- Collect allowlisted metadata only. Never collect record field values other
  than the record label, form contents, HTTP headers, cookies, sessions,
  credentials or environment variables.
- Everything that is sent must be visible in the report dialog.
- Use public TYPO3 APIs only. Do not use XCLASS, `@internal` core APIs,
  generic FormEngine node overrides or DOM manipulation of core markup.
- Check access: reports may only reference pages, records, files and folders
  the reporter can access, in the reporter's current workspace.

## Supported versions

| TYPO3 | PHP | Status |
| --- | --- | --- |
| 13.4 LTS | 8.2, 8.3, 8.4 | Supported |
| 14.3 | 8.2, 8.3, 8.4 | Supported |

One code base supports both TYPO3 versions. Keep both working: do not drop a
TYPO3 13.4 code path because TYPO3 14 offers a cleaner API.

## Development

The PHP checks run in the TYPO3 core-testing Docker images, so the PHP
version of your machine does not matter. Dependencies are installed into
`.Build/`.

```bash
Build/Scripts/runTests.sh -s composer                   # install dependencies for TYPO3 13.4 (default)
Build/Scripts/runTests.sh -t 14 -s composer             # switch the dependencies to TYPO3 14.3
Build/Scripts/runTests.sh -p 8.4 -t 14 -s composer      # the same for another PHP version
Build/Scripts/runTests.sh -s unit                       # unit tests
Build/Scripts/runTests.sh -s functional                 # functional tests (SQLite)
Build/Scripts/runTests.sh -s functional -d mariadb      # functional tests on MariaDB 10.11 (-i for another version)
Build/Scripts/runTests.sh -s functional -d mysql        # functional tests on MySQL 8.0
Build/Scripts/runTests.sh -s stan                       # PHPStan level 8
Build/Scripts/runTests.sh -s cs                         # coding standards (csfix applies them)
Build/Scripts/runTests.sh -s lint                       # PHP syntax
Build/Scripts/runTests.sh -s xlf                        # XLIFF structure and English/German key parity
```

`-t` and `-p` only matter for the `composer` suite: it resolves `.Build/vendor`
for that TYPO3 version and that PHP version. Run it again after switching
either of them, otherwise the other suites fail on the platform check.

JavaScript tests and the bundled Konva library:

```bash
npm ci
npm run test:js
npm run build:konva          # rebuilds Resources/Public/JavaScript/contrib/konva.js
```

Documentation:

```bash
docker run --rm -v "$PWD":/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation --fail-on-log
```

The GitHub workflow in `.github/workflows/ci.yml` runs these checks for TYPO3
13.4 and 14.3 on PHP 8.2, 8.3 and 8.4, the functional tests also on MariaDB
and MySQL. There is no automated browser test suite: backend UI changes are
verified manually in TYPO3 13.4 and 14.3. Describe how you tested them and add
screenshots to the pull request.

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Make one focused change per pull request, with tests.
3. Run the checks on TYPO3 13.4 and 14.3.
4. Add user-facing labels to the English and German XLIFF files (the keys of
   `*.xlf` and `de.*.xlf` must match).
5. Update `CHANGELOG.md` and the documentation if the change affects users.
6. Fill in the pull request template.

## Reporting bugs

Please include the Context Reporter, TYPO3 and PHP versions, the steps to
reproduce, and the expected and actual results. Remove personal data,
secrets and customer details from attached reports and screenshots.
