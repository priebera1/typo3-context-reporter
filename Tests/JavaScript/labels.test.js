import { afterEach, describe, expect, it } from 'vitest';
import { label } from '../../Resources/Public/JavaScript/labels.js';

describe('label', () => {
  afterEach(() => {
    delete globalThis.TYPO3;
  });

  it('reads prefixed labels and replaces placeholders in order', () => {
    globalThis.TYPO3 = { lang: { 'context_reporter.form.description.counter': '%s / %s' } };
    expect(label('form.description.counter', 12, 5000)).toBe('12 / 5000');
  });

  it('falls back to the key when a label is missing', () => {
    globalThis.TYPO3 = { lang: {} };
    expect(label('result.saved', 'CR-1')).toBe('result.saved');
    delete globalThis.TYPO3;
    expect(label('result.saved')).toBe('result.saved');
  });

  it('inserts values literally', () => {
    globalThis.TYPO3 = { lang: { 'context_reporter.demo': 'Sent to %s and %s.' } };
    expect(label('demo', '%s', 'Webhook')).toBe('Sent to %s and Webhook.');
    expect(label('demo', '$&', "$'")).toBe("Sent to $& and $'.");
  });

  it('keeps placeholders without a value', () => {
    globalThis.TYPO3 = { lang: { 'context_reporter.demo': '%s px · annotations: %s' } };
    expect(label('demo', '10×20')).toBe('10×20 px · annotations: %s');
  });
});
