# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x | Yes |

Security fixes are provided for the latest release of TYPO3 Context Reporter.
Update to the latest release before reporting a problem.

## Reporting a vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

Use GitHub private vulnerability reporting:

**[Report a vulnerability privately](https://github.com/priebera1/typo3-context-reporter/security/advisories/new)**

Or email: **support@priebera.sk**

Include:

- a description of the vulnerability;
- steps to reproduce;
- the potential impact;
- the affected Context Reporter, TYPO3 and PHP versions.

Do not include real reports, screenshots, webhook URLs or secrets, or personal
data from production systems.

We aim to acknowledge security reports within 5 business days. Remediation
and disclosure timelines depend on the severity, impact and complexity of the
vulnerability. We will keep you informed while the issue is investigated.

## Scope notes

TYPO3 administrators are trusted with the webhook configuration: they can
choose the endpoint and reference environment variables whose values are sent
to it. See *Privacy and security › Webhook: trusted administrators* in the
documentation for how to pin these settings. Reports about this documented
behavior are welcome as hardening suggestions.

We appreciate responsible disclosure.
