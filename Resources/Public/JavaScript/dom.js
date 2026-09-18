/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

/**
 * Creates an element. Children are appended as nodes or text, never as HTML,
 * so report data can be rendered without escaping concerns.
 *
 * @param {string} tag
 * @param {Object<string, *>} [attributes]
 * @param {...(Node|string|number|null|undefined|false|Array)} children
 * @returns {HTMLElement}
 */
export function h(tag, attributes = {}, ...children) {
  const element = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (value === null || value === undefined || value === false) {
      continue;
    }
    if (name.startsWith('on') && typeof value === 'function') {
      element.addEventListener(name.slice(2).toLowerCase(), value);
    } else if (name === 'dataset') {
      Object.assign(element.dataset, value);
    } else if (name === 'properties') {
      Object.assign(element, value);
    } else {
      element.setAttribute(name, value === true ? '' : String(value));
    }
  }
  appendChildren(element, children);
  return element;
}

/**
 * @param {Node} parent
 * @param {Array} children
 */
export function appendChildren(parent, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) {
      continue;
    }
    parent.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
}

/**
 * @param {string} identifier
 * @returns {HTMLElement}
 */
export function icon(identifier) {
  return h('typo3-backend-icon', { identifier, size: 'small', 'aria-hidden': 'true' });
}

let uniqueCounter = 0;

/**
 * @param {string} prefix
 * @returns {string}
 */
export function uniqueId(prefix) {
  uniqueCounter++;
  return `${prefix}-${Date.now().toString(36)}-${uniqueCounter}`;
}

/**
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(0)} KB`;
  }
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
