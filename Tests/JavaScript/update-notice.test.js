// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DISMISS_ROUTE, dismissUpdateNotice, postDismissal } from '../../Resources/Public/JavaScript/update-notice.js';

const URL = '/typo3/ajax/context-reporter/update-notice/dismiss?token=abc';

describe('update notice', () => {
  beforeEach(() => {
    globalThis.TYPO3 = { settings: { ajaxUrls: { [DISMISS_ROUTE]: URL } } };
    document.body.innerHTML = `
      <div class="context-reporter-module">
        <h1>Context Reports</h1>
        <div class="cr-update-notice" data-context-reporter-update-notice="0.5.0" data-label-dismiss-failed="Could not hide.">
          <button type="button" data-context-reporter-dismiss-update="0.5.0">×</button>
        </div>
      </div>`;
  });

  afterEach(() => {
    delete globalThis.TYPO3;
    vi.unstubAllGlobals();
  });

  const button = () => document.querySelector('[data-context-reporter-dismiss-update]');

  it('sends the version and removes the notice once the server stored it', async () => {
    const send = vi.fn().mockResolvedValue(true);
    button().focus();
    expect(await dismissUpdateNotice(button(), send)).toBe(true);
    expect(send).toHaveBeenCalledWith(URL, '0.5.0');
    expect(document.querySelector('.cr-update-notice')).toBeNull();
    // The focus moves to the heading instead of falling back to the document
    expect(document.activeElement?.tagName).toBe('H1');
  });

  it('keeps the notice and the button usable when the server refuses or is unreachable', async () => {
    for (const send of [vi.fn().mockResolvedValue(false), vi.fn().mockRejectedValue(new Error('offline'))]) {
      expect(await dismissUpdateNotice(button(), send)).toBe(false);
      expect(document.querySelector('.cr-update-notice')).not.toBeNull();
      expect(button().disabled).toBe(false);
      expect(button().getAttribute('title')).toBe('Could not hide.');
    }
  });

  it('does nothing without the AJAX route or a version', async () => {
    const send = vi.fn().mockResolvedValue(true);
    delete globalThis.TYPO3;
    expect(await dismissUpdateNotice(button(), send)).toBe(false);
    globalThis.TYPO3 = { settings: { ajaxUrls: { [DISMISS_ROUTE]: URL } } };
    button().dataset.contextReporterDismissUpdate = '';
    expect(await dismissUpdateNotice(button(), send)).toBe(false);
    expect(send).not.toHaveBeenCalled();
  });

  it('POSTs a form body to the tokenized route and requires success: true', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true, version: '0.5.0' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: false }) })
      .mockResolvedValueOnce({ ok: false, status: 403, json: async () => ({ success: false }) });
    vi.stubGlobal('fetch', fetch);
    expect(await postDismissal(URL, '0.5.0')).toBe(true);
    expect(fetch).toHaveBeenCalledWith(URL, expect.objectContaining({ method: 'POST', credentials: 'same-origin', body: 'version=0.5.0' }));
    expect(await postDismissal(URL, '0.5.0')).toBe(false);
    expect(await postDismissal(URL, '0.5.0')).toBe(false);
  });
});
