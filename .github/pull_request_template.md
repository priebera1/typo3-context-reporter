## What does this change?

Describe the problem and the proposed solution.

## Related issue

Closes #

## Type of change

- [ ] Bug fix
- [ ] New or changed context data
- [ ] Delivery (email, webhook, download)
- [ ] Backend UI
- [ ] Documentation
- [ ] Refactoring (no behavior change)
- [ ] Other

## Testing

- [ ] `Build/Scripts/runTests.sh -s unit` and `-s functional` pass on TYPO3 13.4 and 14.3 (`-t 13` / `-t 14` with the composer suite)
- [ ] The checks pass on PHP 8.2, 8.3 and 8.4 (or CI shows them green)
- [ ] `Build/Scripts/runTests.sh -s stan`, `-s cs` and `-s xlf` pass
- [ ] `npm run test:js` passes
- [ ] Manually tested in TYPO3 13.4 (describe how below)
- [ ] Manually tested in TYPO3 14.3 (describe how below)
- [ ] No unrelated files were changed

## Context data and privacy (skip if not applicable)

- [ ] Not applicable
- [ ] Only allowlisted metadata is collected, and it is shown in the report dialog
- [ ] Access checks cover the new data (permissions, file mounts, workspaces)
- [ ] Documentation updated (Introduction, Privacy and security)

## Screenshots

Add screenshots for backend UI changes. Remove personal data first.

## Checklist

- [ ] User-facing labels are added to the EN and DE XLIFF files
- [ ] CHANGELOG updated if the change affects users
- [ ] No secrets, personal data or client details are included
