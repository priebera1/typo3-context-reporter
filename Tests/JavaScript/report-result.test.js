// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { buildResultView, describeResult } from '../../Resources/Public/JavaScript/report-result.js';

const copy = {
  summary: 'Broken teaser\nReport: CR-AAAA-BBBB-CCCC',
  markdown: '# Broken teaser\n',
  json: '{"id":"CR-AAAA-BBBB-CCCC"}',
  link: 'https://example.com/typo3/module/system/context-reports/show?report=CR-AAAA-BBBB-CCCC',
};

function createResult(overrides = {}) {
  return {
    report: { identifier: 'CR-AAAA-BBBB-CCCC', title: 'Broken <b>teaser</b>', hasScreenshot: false },
    deliveryState: 'local',
    deliveries: [],
    downloads: { markdown: '/download?format=markdown', json: '/download?format=json' },
    historyUrl: '',
    copy,
    ...overrides,
  };
}

function render(result, action = 'send', options = {}) {
  document.body.replaceChildren(...buildResultView(result, action, options));
  return document.body;
}

describe('describeResult', () => {
  beforeEach(() => {
    globalThis.TYPO3 = {
      lang: {
        'context_reporter.result.saved': 'Report %s was saved.',
        'context_reporter.result.delivered': 'Report %s was sent.',
        'context_reporter.result.deliveryFailed': 'Report %s was saved, but not every delivery succeeded.',
        'context_reporter.result.downloaded': 'Report %s was saved and downloaded.',
      },
    };
  });

  afterEach(() => {
    delete globalThis.TYPO3;
  });

  it('confirms that the report was saved, sent or downloaded', () => {
    expect(describeResult(createResult(), 'send')).toEqual({ severity: 'success', message: 'Report CR-AAAA-BBBB-CCCC was saved.' });
    expect(describeResult(createResult(), 'download').message).toBe('Report CR-AAAA-BBBB-CCCC was saved and downloaded.');
    const delivered = createResult({ deliveries: [{ destination: 'email', label: 'Email', successful: true }] });
    expect(describeResult(delivered, 'send')).toEqual({ severity: 'success', message: 'Report CR-AAAA-BBBB-CCCC was sent.' });
  });

  it('warns when a delivery failed', () => {
    const partly = createResult({
      deliveries: [
        { destination: 'email', label: 'Email', successful: true },
        { destination: 'webhook', label: 'Webhook', successful: false },
      ],
    });
    expect(describeResult(partly, 'send').severity).toBe('warning');
    expect(describeResult(partly, 'send').message).toBe('Report CR-AAAA-BBBB-CCCC was saved, but not every delivery succeeded.');
    const failed = createResult({ deliveries: [{ destination: 'webhook', label: 'Webhook', successful: false }] });
    expect(describeResult(failed, 'send').severity).toBe('danger');
  });
});

describe('buildResultView', () => {
  afterEach(() => {
    delete globalThis.TYPO3;
  });

  it('shows the outcome and the report title as text', () => {
    const view = render(createResult());

    expect(view.querySelector('.callout-success .callout-title').textContent).toBe('result.saved');
    expect(view.querySelector('.cr-result__title').textContent).toBe('Broken <b>teaser</b>');
    expect(view.querySelector('b')).toBeNull();
    expect(view.querySelector('.cr-result__deliveries')).toBeNull();
    expect(view.textContent).not.toContain('result.retryHint');
  });

  it('offers the copy texts of the report', () => {
    const items = [...render(createResult()).querySelectorAll('typo3-copy-to-clipboard')];

    expect(items.map((item) => item.getAttribute('text'))).toEqual([copy.summary, copy.markdown, copy.json, copy.link]);
    expect(items.map((item) => item.textContent.trim())).toEqual(['result.copy.summary', 'result.copy.markdown', 'result.copy.json', 'result.copy.link']);
    expect(items.every((item) => item.classList.contains('dropdown-item'))).toBe(true);
    const toggle = items[0].closest('.btn-group').querySelector('[data-bs-toggle="dropdown"]');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.textContent.trim()).toBe('result.copy');
  });

  it('leaves out copy texts the server did not send', () => {
    const view = render(createResult({ copy: { summary: 'Only a summary', link: '' } }));

    expect([...view.querySelectorAll('typo3-copy-to-clipboard')].map((item) => item.getAttribute('text'))).toEqual(['Only a summary']);
    expect(render(createResult({ copy: undefined })).textContent).not.toContain('result.copy');
  });

  it('offers the downloads of the report', () => {
    const withoutScreenshot = [...render(createResult()).querySelectorAll('a[download]')];
    expect(withoutScreenshot.map((link) => [link.getAttribute('href'), link.textContent.trim()])).toEqual([
      ['/download?format=markdown', 'result.download.markdown'],
      ['/download?format=json', 'result.download.json'],
    ]);

    const result = createResult({
      report: { identifier: 'CR-AAAA-BBBB-CCCC', title: 'Broken teaser', hasScreenshot: true },
      downloads: { markdown: '/md', json: '/json', screenshot: '/png' },
    });
    const withScreenshot = [...render(result).querySelectorAll('a[download]')];
    expect(withScreenshot.map((link) => link.textContent.trim())).toEqual([
      'result.download.markdown',
      'result.download.jsonWithScreenshot',
      'result.download.screenshot',
    ]);
  });

  it('lets administrators open the report', () => {
    expect(render(createResult()).querySelector('.cr-result__open')).toBeNull();

    const onOpenReport = vi.fn((event) => event.preventDefault());
    const view = render(createResult({ historyUrl: '/typo3/module/system/context-reports/show?report=CR-AAAA-BBBB-CCCC' }), 'send', { onOpenReport });
    const open = view.querySelector('a.cr-result__open');
    expect(open.getAttribute('href')).toBe('/typo3/module/system/context-reports/show?report=CR-AAAA-BBBB-CCCC');
    expect(open.textContent.trim()).toBe('result.openReport');

    open.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(onOpenReport).toHaveBeenCalledOnce();
    expect(onOpenReport.mock.calls[0][1]).toBe('/typo3/module/system/context-reports/show?report=CR-AAAA-BBBB-CCCC');
  });

  it('lists deliveries with safe external links only', () => {
    const view = render(createResult({
      deliveries: [
        { destination: 'webhook', label: 'Webhook', successful: true, externalReference: 'SUP-42', externalUrl: 'https://desk.example.com/tickets/42' },
        { destination: 'other', label: 'Other', successful: true, externalReference: 'X-1', externalUrl: 'javascript:alert(1)' },
        { destination: 'email', label: 'Email', successful: false },
      ],
    }));

    const entries = [...view.querySelectorAll('.cr-result__deliveries li')];
    expect(entries).toHaveLength(3);
    const link = entries[0].querySelector('a');
    expect(link.getAttribute('href')).toBe('https://desk.example.com/tickets/42');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    expect(entries[1].querySelector('a')).toBeNull();
    expect(entries[1].textContent).toContain('X-1');
    expect(view.textContent).toContain('result.retryHint');
  });
});
