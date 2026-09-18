/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import { createRequestFromTrigger, requestReport } from '@priebera/context-reporter/report-request.js';

/**
 * Handles clicks on elements with a data-context-reporter-trigger attribute
 * (toolbar item, record editor button). Delegated, so re-rendered markup
 * (e.g. a refreshed toolbar) keeps working.
 */
document.addEventListener('click', (event) => {
  const trigger = event.target instanceof Element ? event.target.closest('[data-context-reporter-trigger]') : null;
  if (!(trigger instanceof HTMLElement)) {
    return;
  }
  event.preventDefault();
  requestReport(createRequestFromTrigger(trigger));
});
