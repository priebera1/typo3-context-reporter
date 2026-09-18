/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [tabindex]';

/**
 * Report history: a click anywhere on a report row opens the report, like a
 * click on its title. The title stays the real, keyboard accessible link;
 * links and controls inside the row keep their own behavior.
 *
 * @param {MouseEvent} event
 * @returns {HTMLAnchorElement|null} The report link to follow
 */
export function findRowLink(event) {
  const target = event.target instanceof Element ? event.target : null;
  if (target === null
    || event.defaultPrevented
    || event.button !== 0
    || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
    || target.closest(INTERACTIVE) !== null
  ) {
    return null;
  }
  const row = target.closest('[data-context-reporter-row]');
  if (row === null) {
    return null;
  }
  // Selecting text in a row must not open the report
  const selection = target.ownerDocument.defaultView?.getSelection();
  if (selection && !selection.isCollapsed && row.contains(selection.anchorNode)) {
    return null;
  }
  const link = row.querySelector('a[data-context-reporter-row-link]') ?? row.querySelector('a[href]');
  return link instanceof HTMLAnchorElement ? link : null;
}

document.addEventListener('click', (event) => {
  findRowLink(event)?.click();
});
