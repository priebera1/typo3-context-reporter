/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

/**
 * Small decisions of the report dialog that do not need TYPO3's modal: which
 * element gets the focus, when closing needs a confirmation and how a field
 * is tied to its error message.
 */

/**
 * @param {Element|null|undefined} element
 * @returns {boolean}
 */
export function isTextEntry(element) {
  if (!element || typeof element !== 'object') {
    return false;
  }
  if (element.isContentEditable) {
    return true;
  }
  const tagName = String(element.tagName ?? '').toUpperCase();
  if (tagName === 'TEXTAREA') {
    return true;
  }
  if (tagName !== 'INPUT') {
    return false;
  }
  const type = String(element.type ?? 'text').toLowerCase();
  return ['text', 'search', 'email', 'url', 'tel', 'password', 'number'].includes(type);
}

/**
 * After a screenshot was added with the buttons or by dropping a file, the
 * editor takes the focus, so the tool shortcuts (V, R, A, D, T, B) reach it.
 * Whoever is typing keeps the focus: an image pasted into the title or the
 * description, or a text field the reporter moved to while it was loading.
 *
 * @param {Element|null} activeElement focus now
 * @param {Element|null} focusAtStart focus when the screenshot was requested
 * @returns {boolean}
 */
export function shouldFocusEditor(activeElement, focusAtStart) {
  return !isTextEntry(focusAtStart) && !isTextEntry(activeElement);
}

/**
 * Closing the dialog keeps title and description as a draft, but not the
 * screenshot: closing with a screenshot asks first.
 *
 * @param {{width: number, height: number, annotations: number}|null|undefined} screenshot
 * @returns {boolean}
 */
export function needsDiscardConfirmation(screenshot) {
  return Boolean(screenshot && screenshot.width > 0 && screenshot.height > 0);
}

/**
 * Marks a field as invalid and points it to the message that says why, or
 * removes both again. Other aria-describedby references (hints, counters) stay.
 *
 * @param {HTMLElement} field
 * @param {HTMLElement} message element with an id
 * @param {boolean} invalid
 */
export function setFieldError(field, message, invalid) {
  const ids = (field.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((id) => id !== '' && id !== message.id);
  if (invalid) {
    field.setAttribute('aria-invalid', 'true');
    field.classList.add('is-invalid');
    ids.unshift(message.id);
  } else {
    field.removeAttribute('aria-invalid');
    field.classList.remove('is-invalid');
  }
  if (ids.length > 0) {
    field.setAttribute('aria-describedby', ids.join(' '));
  } else {
    field.removeAttribute('aria-describedby');
  }
}
