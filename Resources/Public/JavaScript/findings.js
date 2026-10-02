/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import { h, icon, uniqueId } from '@priebera/context-reporter/dom.js';

/**
 * The findings below the detected object: short notices about the website
 * address, placement, visibility, files and permissions, grouped as the
 * server prepared them in the reporter's backend language, with one note
 * that they are stored settings and permission facts. Nothing is rendered
 * without findings.
 *
 * @param {{title: string, groups: Array<{key: string, title: string, icon?: string, items: string[], moreText?: string}>, note?: string}|null|undefined} findings
 * @returns {HTMLElement|null}
 */
export function buildFindings(findings) {
  const groups = (Array.isArray(findings?.groups) ? findings.groups : [])
    .map((group) => ({ ...group, items: Array.isArray(group?.items) ? group.items.filter((item) => typeof item === 'string' && item !== '') : [] }))
    .filter((group) => group.items.length > 0);
  if (groups.length === 0) {
    return null;
  }
  const titleId = uniqueId('cr-findings-title');
  return h('section', { class: 'cr-findings', 'aria-labelledby': titleId },
    h('p', { class: 'cr-findings__title', id: titleId }, String(findings.title ?? '')),
    groups.map((group) => {
      const groupTitleId = uniqueId('cr-findings-group');
      return h('section', { class: 'cr-findings__group', 'aria-labelledby': groupTitleId, dataset: { group: String(group.key ?? '') } },
        h('p', { class: 'cr-findings__group-title', id: groupTitleId }, group.icon ? icon(String(group.icon)) : null, String(group.title ?? '')),
        h('ul', { class: 'cr-findings__list' }, group.items.map((item) => h('li', {}, item))),
        group.moreText ? h('p', { class: 'cr-findings__more' }, String(group.moreText)) : null,
      );
    }),
    findings.note ? h('p', { class: 'cr-findings__note' }, String(findings.note)) : null,
  );
}
