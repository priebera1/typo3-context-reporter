/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import { h, icon, uniqueId } from '@priebera/context-reporter/dom.js';

/**
 * The visibility notices below the detected object: stored settings that keep
 * the object from website visitors, as the server prepared them in the
 * reporter's backend language. Nothing is rendered without notices.
 *
 * @param {{title: string, notices: string[], note: string}|null|undefined} visibility
 * @returns {HTMLElement|null}
 */
export function buildVisibilityNotices(visibility) {
  const notices = Array.isArray(visibility?.notices)
    ? visibility.notices.filter((notice) => typeof notice === 'string' && notice !== '')
    : [];
  if (notices.length === 0) {
    return null;
  }
  const titleId = uniqueId('cr-visibility-title');
  return h('section', { class: 'cr-visibility', 'aria-labelledby': titleId },
    h('p', { class: 'cr-visibility__title', id: titleId }, icon('actions-eye'), String(visibility.title ?? '')),
    h('ul', { class: 'cr-visibility__list' }, notices.map((notice) => h('li', {}, notice))),
    visibility.note ? h('p', { class: 'cr-visibility__note' }, String(visibility.note)) : null,
  );
}
