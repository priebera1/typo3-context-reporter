/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

export const OPEN_EVENT = 'typo3:context-reporter:open';

const SOURCES = ['toolbar', 'contextMenu', 'formEngine', 'recordList', 'pageModule', 'fileList'];
const FILE_TABLE = 'sys_file';
const MAX_FOLDER_IDENTIFIER_LENGTH = 1024;

/**
 * Builds the report request from the data attributes of a trigger element:
 * data-context-reporter-trigger (entry point), and either
 * data-context-reporter-table + data-context-reporter-uid (page, record, file)
 * or data-context-reporter-folder (combined folder identifier).
 *
 * @param {HTMLElement} trigger
 * @returns {{source: string, target: Object}}
 */
export function createRequestFromTrigger(trigger) {
  const { dataset } = trigger;
  const source = SOURCES.includes(dataset.contextReporterTrigger) ? dataset.contextReporterTrigger : 'toolbar';
  if (dataset.contextReporterFolder !== undefined) {
    return { source, target: createFolderTarget(dataset.contextReporterFolder) };
  }
  return { source, target: createTarget(dataset.contextReporterTable ?? '', parseUid(dataset.contextReporterUid)) };
}

/**
 * @param {string} table
 * @param {number} uid
 * @returns {Object}
 */
export function createTarget(table, uid) {
  if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(table) || !Number.isInteger(uid) || uid <= 0) {
    return {};
  }
  if (table === 'pages') {
    return { type: 'page', uid };
  }
  return table === FILE_TABLE ? { type: 'file', uid } : { type: 'record', table, uid };
}

/**
 * @param {string} identifier Combined identifier, e.g. "1:/user_upload/"
 * @returns {Object}
 */
export function createFolderTarget(identifier) {
  if (typeof identifier !== 'string'
    || identifier.length > MAX_FOLDER_IDENTIFIER_LENGTH
    || !/^[1-9]\d{0,9}:\/[^\u0000-\u001f\u007f]*$/.test(identifier)
  ) {
    return {};
  }
  return { type: 'folder', identifier };
}

/**
 * The target of a context menu item. Files and folders are identified by a
 * combined identifier; their items tell which one it is.
 *
 * @param {string} table
 * @param {number|string} uid
 * @param {Object<string, string>} [dataset] Data attributes of the menu item
 * @returns {Object}
 */
export function createTargetFromContextMenu(table, uid, dataset = {}) {
  if (dataset.contextReporterTarget === 'folder') {
    return createFolderTarget(String(uid));
  }
  if (dataset.contextReporterTarget === 'file') {
    return createTarget(FILE_TABLE, parseUid(dataset.contextReporterUid));
  }
  return table === FILE_TABLE ? {} : createTarget(String(table), parseUid(uid));
}

/**
 * @param {*} value
 * @returns {number}
 */
function parseUid(value) {
  return /^\d{1,10}$/.test(String(value ?? '')) ? Number(value) : Number.NaN;
}

/**
 * Opens the report dialog in the top backend frame, so it is not bound to
 * the lifetime of a module iframe. Outside of the backend frame set (e.g. a
 * record opened in a new window) the dialog opens in the current window.
 *
 * @param {{source: string, target: Object}} request
 */
export function requestReport(request) {
  const payload = JSON.stringify(request);
  try {
    const topWindow = window.top;
    if (topWindow && topWindow !== window) {
      const event = new topWindow.CustomEvent(OPEN_EVENT, { cancelable: true, detail: payload });
      if (!topWindow.document.dispatchEvent(event)) {
        return;
      }
    }
  } catch {
    // The top window is not accessible; fall back to the current window
  }
  import('@priebera/context-reporter/report-dialog.js').then(({ openReportDialog }) => {
    openReportDialog(JSON.parse(payload));
  });
}
