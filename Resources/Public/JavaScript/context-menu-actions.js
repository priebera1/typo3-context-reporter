/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import { createTargetFromContextMenu, requestReport } from '@priebera/context-reporter/report-request.js';

/**
 * Callbacks of the "Report a problem" context menu items (pages, records,
 * files and folders).
 */
class ContextMenuActions {
  /**
   * @param {string} table
   * @param {number|string} uid
   * @param {Object<string, string>} [dataset]
   */
  reportProblem(table, uid, dataset) {
    requestReport({
      source: 'contextMenu',
      target: createTargetFromContextMenu(String(table), uid, dataset ?? {}),
    });
  }
}

export default new ContextMenuActions();
