/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

// "New Context Reporter version available" in System › Context Reports (administrators). Dismissing hides that
// release for the current backend user on the server; the notice disappears only after the server stored it.

export const DISMISS_ROUTE = 'context_reporter_update_notice_dismiss';
const NOTICE_SELECTOR = '[data-context-reporter-update-notice]';
const DISMISS_SELECTOR = '[data-context-reporter-dismiss-update]';

/**
 * POSTs the version to the AJAX route. TYPO3 puts the route token into the URL of every AJAX route, so the request
 * is refused without it; the session cookie goes along (same origin).
 *
 * @param {string} url
 * @param {string} version
 * @returns {Promise<boolean>}
 */
export async function postDismissal(url, version) {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams({ version }).toString(),
  });
  if (!response.ok) {
    return false;
  }
  const data = await response.json();
  return data?.success === true;
}

const showFailure = (notice, button) => {
  const message = notice.dataset.labelDismissFailed || 'The notice could not be hidden. Try again.';
  const notification = globalThis.top?.TYPO3?.Notification;
  if (notification && typeof notification.warning === 'function') {
    notification.warning('Context Reporter', message, 5);
    return;
  }
  button.setAttribute('title', message);
};

/**
 * Keyboard users keep their place: when the dismissed notice held the focus, it moves to the module heading.
 *
 * @param {HTMLElement} notice
 */
const moveFocusAfterRemoval = (notice) => {
  const heading = (notice.closest('.context-reporter-module') ?? notice.ownerDocument).querySelector('h1');
  if (!(heading instanceof HTMLElement)) {
    return;
  }
  if (!heading.hasAttribute('tabindex')) {
    heading.setAttribute('tabindex', '-1');
    heading.addEventListener('blur', () => heading.removeAttribute('tabindex'), { once: true });
  }
  heading.focus();
};

/**
 * @param {HTMLButtonElement} button
 * @param {(url: string, version: string) => Promise<boolean>} [send]
 * @returns {Promise<boolean>}
 */
export async function dismissUpdateNotice(button, send = postDismissal) {
  const notice = button.closest(NOTICE_SELECTOR);
  const url = globalThis.TYPO3?.settings?.ajaxUrls?.[DISMISS_ROUTE];
  const version = String(button.dataset.contextReporterDismissUpdate || '').trim();
  if (!(notice instanceof HTMLElement) || typeof url !== 'string' || url === '' || version === '') {
    return false;
  }
  const hadFocus = notice.contains(notice.ownerDocument.activeElement);
  button.disabled = true;
  let stored = false;
  try {
    stored = await send(url, version);
  } catch {
    stored = false;
  }
  if (!stored) {
    button.disabled = false;
    showFailure(notice, button);
    return false;
  }
  if (hadFocus) {
    moveFocusAfterRemoval(notice);
  }
  notice.remove();
  return true;
}

/**
 * @param {Document|HTMLElement} root
 */
export function initializeUpdateNotice(root) {
  root.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest(DISMISS_SELECTOR) : null;
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    event.preventDefault();
    dismissUpdateNotice(button);
  });
}

if (typeof document !== 'undefined') {
  initializeUpdateNotice(document);
}
