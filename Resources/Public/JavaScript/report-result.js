/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import { h, icon } from '@priebera/context-reporter/dom.js';
import { label } from '@priebera/context-reporter/labels.js';

const COPY_ITEMS = [
  { key: 'summary', icon: 'mimetypes-text-text' },
  { key: 'markdown', icon: 'actions-file-text' },
  { key: 'json', icon: 'actions-code' },
  { key: 'link', icon: 'actions-link' },
];

/**
 * @param {Object} result Response of the submit endpoint
 * @param {'send'|'download'} action
 * @returns {{severity: 'success'|'warning'|'danger', message: string}}
 */
export function describeResult(result, action) {
  const deliveries = result.deliveries ?? [];
  const failed = deliveries.filter((delivery) => !delivery.successful).length;
  const identifier = result.report.identifier;
  if (failed > 0) {
    return {
      severity: failed === deliveries.length ? 'danger' : 'warning',
      message: label('result.deliveryFailed', identifier),
    };
  }
  if (deliveries.length > 0) {
    return { severity: 'success', message: label('result.delivered', identifier) };
  }
  return { severity: 'success', message: label(action === 'download' ? 'result.downloaded' : 'result.saved', identifier) };
}

/**
 * The compact view after a report was submitted: the outcome and what can be
 * done with the report next. The report itself is not repeated.
 *
 * Copy items use core's <typo3-copy-to-clipboard> element, which the caller loads.
 *
 * @param {Object} result Response of the submit endpoint
 * @param {'send'|'download'} action
 * @param {{onOpenReport?: function(MouseEvent, string): void}} [options]
 * @returns {HTMLElement[]}
 */
export function buildResultView(result, action, options = {}) {
  const { severity, message } = describeResult(result, action);
  const deliveries = result.deliveries ?? [];
  const copy = result.copy ?? {};
  const downloads = result.downloads ?? {};

  const copyItems = COPY_ITEMS
    .filter((item) => typeof copy[item.key] === 'string' && copy[item.key] !== '')
    .map((item) => h('typo3-copy-to-clipboard', { class: 'dropdown-item dropdown-item-spaced', text: copy[item.key] },
      icon(item.icon),
      label(`result.copy.${item.key}`),
    ));
  const downloadItems = [
    { key: 'markdown', icon: 'actions-file-text', label: 'result.download.markdown' },
    { key: 'json', icon: 'actions-code', label: result.report.hasScreenshot ? 'result.download.jsonWithScreenshot' : 'result.download.json' },
    { key: 'screenshot', icon: 'actions-image', label: 'result.download.screenshot' },
  ]
    .filter((item) => typeof downloads[item.key] === 'string' && downloads[item.key] !== '')
    .map((item) => h('a', { class: 'dropdown-item dropdown-item-spaced', href: downloads[item.key], download: '' },
      icon(item.icon),
      label(item.label),
    ));

  return [
    h('div', { class: `callout callout-${severity} cr-result` },
      h('div', { class: 'callout-content' },
        h('div', { class: 'callout-title' }, message),
        h('div', { class: 'callout-body' },
          h('p', { class: 'cr-result__title' }, result.report.title),
          deliveries.length > 0 ? buildDeliveryList(deliveries) : null,
          deliveries.some((delivery) => !delivery.successful) ? h('p', {}, label('result.retryHint')) : null,
        ),
      ),
    ),
    h('div', { class: 'cr-result__actions' },
      result.historyUrl
        ? h('a', {
          class: 'btn btn-default cr-result__open',
          href: result.historyUrl,
          onclick: (event) => options.onOpenReport?.(event, result.historyUrl),
        }, icon('actions-eye'), ' ', label('result.openReport'))
        : null,
      buildMenu('actions-clipboard', label('result.copy'), copyItems),
      buildMenu('actions-download', label('result.download'), downloadItems),
    ),
  ];
}

/**
 * @param {Array<Object>} deliveries
 * @returns {HTMLElement}
 */
function buildDeliveryList(deliveries) {
  return h('ul', { class: 'cr-result__deliveries' },
    deliveries.map((delivery) => h('li', {},
      icon(delivery.successful ? 'actions-check-circle' : 'actions-exclamation-circle'),
      ' ',
      label(delivery.successful ? 'result.deliverySucceeded' : 'result.deliveryFailedTo', delivery.label),
      delivery.externalReference
        ? [' · ', /^https?:\/\//i.test(delivery.externalUrl ?? '')
          ? h('a', { href: delivery.externalUrl, target: '_blank', rel: 'noopener noreferrer' }, delivery.externalReference)
          : delivery.externalReference]
        : null,
    )),
  );
}

/**
 * A dropdown like the ones in the document header of the report detail.
 *
 * @param {string} iconIdentifier
 * @param {string} text
 * @param {HTMLElement[]} items
 * @returns {HTMLElement|null}
 */
function buildMenu(iconIdentifier, text, items) {
  if (items.length === 0) {
    return null;
  }
  return h('div', { class: 'btn-group' },
    h('button', {
      type: 'button',
      class: 'btn btn-default dropdown-toggle',
      'data-bs-toggle': 'dropdown',
      // The modal body scrolls: a fixed menu is not cut off at its edge
      'data-bs-popper-config': '{"strategy":"fixed"}',
      'aria-expanded': 'false',
    }, icon(iconIdentifier), ' ', text),
    h('ul', { class: 'dropdown-menu' }, items.map((item) => h('li', {}, item))),
  );
}
