/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

const PREFIX = 'context_reporter.';

/**
 * Reads a label from TYPO3.lang (filled from locallang_js.xlf).
 * Occurrences of "%s" are replaced by the given values in order.
 *
 * @param {string} key Key without the "context_reporter." prefix
 * @param {...(string|number)} values
 * @returns {string}
 */
export function label(key, ...values) {
  const labels = globalThis.TYPO3?.lang ?? {};
  let text = labels[PREFIX + key];
  if (typeof text !== 'string' || text === '') {
    text = key;
  }
  let index = 0;
  return text.replace(/%s/g, (placeholder) => (index < values.length ? String(values[index++]) : placeholder));
}
