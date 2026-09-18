/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

/**
 * Parameters that must never leave the browser: CSRF tokens and return URLs.
 */
const DROPPED_PARAMETERS = ['token', 'returnUrl', 'redirect', 'redirectParams'];

/**
 * Path and query of a backend URL without tokens and return URLs.
 * The server applies its own allowlist on top.
 *
 * @param {string} href
 * @param {string} base
 * @returns {string}
 */
export function sanitizeBackendUrl(href, base) {
  let url;
  try {
    url = new URL(href, base);
  } catch {
    return '';
  }
  for (const parameter of DROPPED_PARAMETERS) {
    url.searchParams.delete(parameter);
  }
  return url.pathname + url.search;
}

/**
 * Describes where in the backend the reporter currently is: the document in
 * the module content frame (or the current window outside of the backend
 * frame set), the highlighted module and the page tree selection.
 *
 * @param {Window} win
 * @param {string} pageTreeSelection Identifier of the page selected in the page tree
 * @returns {{url: string, module: string, activeModule: string, pageTreeSelection: string}}
 */
export function collectLocation(win, pageTreeSelection = '') {
  const location = { url: '', module: '', activeModule: '', pageTreeSelection: /^\d+$/.test(pageTreeSelection) ? pageTreeSelection : '' };
  const backend = win.TYPO3?.Backend;
  try {
    const contentWindow = typeof backend?.ContentContainer?.get === 'function' ? backend.ContentContainer.get() : null;
    const target = contentWindow?.document ? contentWindow : win;
    location.url = sanitizeBackendUrl(target.location.href, win.location.origin);
    location.module = target.document.querySelector('.module[data-module-name]')?.dataset.moduleName ?? '';
  } catch {
    // The content frame is not accessible
  }
  try {
    const activeModule = win.TYPO3?.ModuleMenu?.App?.getCurrentModule?.();
    location.activeModule = typeof activeModule === 'string' ? activeModule : '';
  } catch {
    // The module menu is not available in this window
  }
  return location;
}

/**
 * Browser facts that help to reproduce layout and input problems.
 * Nothing that identifies the user, no storage, no cookies.
 *
 * @param {Window} win
 * @returns {Object<string, *>}
 */
export function collectBrowserInfo(win = window) {
  const navigator = win.navigator;
  const clientHints = navigator.userAgentData;
  const info = {
    userAgent: navigator.userAgent,
    platform: typeof clientHints?.platform === 'string' ? clientHints.platform : '',
    touch: (navigator.maxTouchPoints ?? 0) > 0,
    language: navigator.language,
    languages: Array.from(navigator.languages ?? []).slice(0, 5),
    viewport: { width: win.innerWidth, height: win.innerHeight },
    screen: { width: win.screen?.width ?? 0, height: win.screen?.height ?? 0 },
    devicePixelRatio: win.devicePixelRatio,
    colorScheme: win.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
    backendColorScheme: win.document.documentElement.dataset.colorScheme ?? 'auto',
    reducedMotion: Boolean(win.matchMedia?.('(prefers-reduced-motion: reduce)').matches),
  };
  if (typeof clientHints?.mobile === 'boolean') {
    info.mobile = clientHints.mobile;
  }
  try {
    info.timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  } catch {
    // Time zone not available
  }
  return info;
}
