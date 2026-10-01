/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import { h, icon, uniqueId } from '@priebera/context-reporter/dom.js';

/**
 * The file check notices below the detected object: problems of the
 * reported file or of the files its references point to, and references
 * that could not be checked, as the server prepared them in the reporter's
 * backend language. Nothing is rendered without notices.
 *
 * @param {{title: string, notices: string[], note: string}|null|undefined} fileChecks
 * @returns {HTMLElement|null}
 */
export function buildFileCheckNotices(fileChecks) {
  const notices = Array.isArray(fileChecks?.notices)
    ? fileChecks.notices.filter((notice) => typeof notice === 'string' && notice !== '')
    : [];
  if (notices.length === 0) {
    return null;
  }
  const titleId = uniqueId('cr-file-checks-title');
  return h('section', { class: 'cr-file-checks', 'aria-labelledby': titleId },
    h('p', { class: 'cr-file-checks__title', id: titleId }, icon('actions-file'), String(fileChecks.title ?? '')),
    h('ul', { class: 'cr-file-checks__list' }, notices.map((notice) => h('li', {}, notice))),
    fileChecks.note ? h('p', { class: 'cr-file-checks__note' }, String(fileChecks.note)) : null,
  );
}
