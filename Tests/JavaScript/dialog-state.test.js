// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { isTextEntry, needsDiscardConfirmation, setFieldError, shouldFocusEditor } from '../../Resources/Public/JavaScript/dialog-state.js';

function element(html) {
  const container = document.createElement('div');
  container.innerHTML = html;
  document.body.append(container);
  return container.firstElementChild;
}

describe('isTextEntry', () => {
  it('detects fields that take typed characters', () => {
    expect(isTextEntry(element('<input type="text">'))).toBe(true);
    expect(isTextEntry(element('<input>'))).toBe(true);
    expect(isTextEntry(element('<textarea></textarea>'))).toBe(true);
    expect(isTextEntry(element('<input type="search">'))).toBe(true);
  });

  it('ignores buttons, file inputs, the canvas host and empty values', () => {
    expect(isTextEntry(element('<button type="button">Upload</button>'))).toBe(false);
    expect(isTextEntry(element('<input type="file">'))).toBe(false);
    expect(isTextEntry(element('<input type="checkbox">'))).toBe(false);
    expect(isTextEntry(element('<div tabindex="0" role="img"></div>'))).toBe(false);
    expect(isTextEntry(null)).toBe(false);
    expect(isTextEntry(undefined)).toBe(false);
  });
});

describe('shouldFocusEditor', () => {
  it('leaves the focus in a text field an image was pasted into', () => {
    const description = element('<textarea></textarea>');
    expect(shouldFocusEditor(description, description)).toBe(false);
  });

  it('moves the focus from buttons and the dialog itself', () => {
    const upload = element('<button type="button">Upload image</button>');
    expect(shouldFocusEditor(upload, upload)).toBe(true);
    expect(shouldFocusEditor(document.body, upload)).toBe(true);
  });

  it('does not take the focus from a field the reporter started typing in meanwhile', () => {
    const upload = element('<button type="button">Upload image</button>');
    const title = element('<input type="text">');
    expect(shouldFocusEditor(title, upload)).toBe(false);
  });
});

describe('needsDiscardConfirmation', () => {
  it('asks only when a screenshot would be lost', () => {
    expect(needsDiscardConfirmation(null)).toBe(false);
    expect(needsDiscardConfirmation(undefined)).toBe(false);
    expect(needsDiscardConfirmation({ width: 800, height: 500, annotations: 0 })).toBe(true);
    expect(needsDiscardConfirmation({ width: 800, height: 500, annotations: 3 })).toBe(true);
    expect(needsDiscardConfirmation({ width: 0, height: 0, annotations: 0 })).toBe(false);
  });
});

describe('setFieldError', () => {
  it('ties the field to its message and keeps other descriptions', () => {
    const field = element('<input type="text" aria-describedby="hint">');
    const message = element('<div id="error-1"></div>');

    setFieldError(field, message, true);
    expect(field.getAttribute('aria-invalid')).toBe('true');
    expect(field.getAttribute('aria-describedby')).toBe('error-1 hint');
    expect(field.classList.contains('is-invalid')).toBe(true);

    setFieldError(field, message, true);
    expect(field.getAttribute('aria-describedby')).toBe('error-1 hint');

    setFieldError(field, message, false);
    expect(field.hasAttribute('aria-invalid')).toBe(false);
    expect(field.getAttribute('aria-describedby')).toBe('hint');
    expect(field.classList.contains('is-invalid')).toBe(false);
  });

  it('removes aria-describedby when nothing else describes the field', () => {
    const field = element('<input type="text">');
    const message = element('<div id="error-2"></div>');
    setFieldError(field, message, true);
    setFieldError(field, message, false);
    expect(field.hasAttribute('aria-describedby')).toBe(false);
  });
});
