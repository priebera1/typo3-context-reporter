// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { buildResultView, describeResult, findHubReceipt, findHubScreenshotNotice } from '../../Resources/Public/JavaScript/report-result.js';

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
        'context_reporter.result.receivedByHub': 'Report received as %s in Context Reporter Hub.',
        'context_reporter.result.deliveryUnconfirmed': 'Report %s was saved. Its delivery was not confirmed in time; it may still have arrived.',
      },
    };
  });

  it('does not claim that an unconfirmed Hub delivery failed', () => {
    const lost = { destination: 'hub', label: 'Context Reporter Hub', successful: false, unconfirmed: true };
    expect(describeResult(createResult({ deliveries: [lost] }), 'send')).toEqual({
      severity: 'warning',
      message: 'Report CR-AAAA-BBBB-CCCC was saved. Its delivery was not confirmed in time; it may still have arrived.',
    });
    // A delivery that certainly failed next to it keeps the failure message
    const mixed = createResult({ deliveries: [lost, { destination: 'email', label: 'Email', successful: false }] });
    expect(describeResult(mixed, 'send').message).toBe('Report CR-AAAA-BBBB-CCCC was saved, but not every delivery succeeded.');
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

  it('returns only nodes, so no "null" text appears without a screenshot notice', () => {
    const nodes = buildResultView(createResult(), 'send');

    expect(nodes.every((node) => node instanceof Node)).toBe(true);
    expect(render(createResult()).textContent).not.toContain('null');
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

  it('lists an unconfirmed Hub delivery as not confirmed and says that a retry is safe', () => {
    const view = render(createResult({
      deliveries: [
        { destination: 'hub', label: 'Context Reporter Hub', successful: false, unconfirmed: true, externalReference: '', externalUrl: '' },
      ],
    }));
    const entries = [...view.querySelectorAll('.cr-result__deliveries li')];
    expect(entries).toHaveLength(1);
    expect(entries[0].textContent).toContain('result.deliveryUnconfirmedTo');
    expect(entries[0].textContent).not.toContain('result.deliveryFailedTo');
    expect(view.textContent).toContain('result.retryHint');
    expect(view.textContent).toContain('result.unconfirmedHint');

    const failed = render(createResult({ deliveries: [{ destination: 'hub', label: 'Context Reporter Hub', successful: false }] }));
    expect(failed.textContent).toContain('result.deliveryFailedTo');
    expect(failed.textContent).not.toContain('result.unconfirmedHint');
  });

  it('names the Hub reference and links into the Hub after a Hub delivery', () => {
    const hub = { destination: 'hub', label: 'Context Reporter Hub', successful: true, externalReference: 'LW-184', externalUrl: 'https://hub.example.com/app/reports/LW-184' };
    const view = render(createResult({ deliveries: [hub] }));
    const open = view.querySelector('a.cr-result__hub');
    expect(open.getAttribute('href')).toBe('https://hub.example.com/app/reports/LW-184');
    expect(open.getAttribute('target')).toBe('_blank');
    expect(open.getAttribute('rel')).toBe('noopener noreferrer');
    expect(open.textContent.trim()).toBe('result.openInHub');

    // The generic webhook keeps its reference in the delivery list only
    const webhook = render(createResult({ deliveries: [{ ...hub, destination: 'webhook', label: 'Webhook' }] }));
    expect(webhook.querySelector('a.cr-result__hub')).toBeNull();
    // Unsafe links are not offered
    expect(render(createResult({ deliveries: [{ ...hub, externalUrl: 'javascript:alert(1)' }] })).querySelector('a.cr-result__hub')).toBeNull();
  });

  it('names the Hub reference without a link when the link was not on the trusted Hub address', () => {
    // The server drops links outside the trusted Hub address: received, but nothing to open
    const hub = { destination: 'hub', label: 'Context Reporter Hub', successful: true, externalReference: 'LW-184', externalUrl: '' };
    const view = render(createResult({ deliveries: [hub] }));
    expect(view.querySelector('a.cr-result__hub')).toBeNull();
    expect(view.querySelector('.cr-result__deliveries a')).toBeNull();
    expect(view.textContent).toContain('LW-184');
  });
});

describe('findHubReceipt', () => {
  beforeEach(() => {
    globalThis.TYPO3 = { lang: { 'context_reporter.result.receivedByHub': 'Report received as %s in Context Reporter Hub.', 'context_reporter.result.deliveryFailed': 'Report %s was saved, but not every delivery succeeded.' } };
  });

  afterEach(() => {
    delete globalThis.TYPO3;
  });

  const hub = { destination: 'hub', successful: true, externalReference: 'LW-184', externalUrl: 'https://hub.example.com/app/reports/LW-184' };

  it('reads the reference of a successful Hub delivery', () => {
    expect(findHubReceipt(createResult({ deliveries: [hub] }))).toEqual({ reference: 'LW-184', url: 'https://hub.example.com/app/reports/LW-184' });
    expect(findHubReceipt(createResult({ deliveries: [{ ...hub, externalUrl: 'ftp://x' }] }))).toEqual({ reference: 'LW-184', url: '' });
    expect(findHubReceipt(createResult({ deliveries: [{ ...hub, successful: false }] }))).toBeNull();
    expect(findHubReceipt(createResult({ deliveries: [{ ...hub, externalReference: '' }] }))).toBeNull();
    expect(findHubReceipt(createResult({ deliveries: [{ ...hub, destination: 'webhook' }] }))).toBeNull();
  });

  it('puts the Hub reference into the message unless a delivery failed', () => {
    expect(describeResult(createResult({ deliveries: [hub] }), 'send')).toEqual({ severity: 'success', message: 'Report received as LW-184 in Context Reporter Hub.' });
    const partly = createResult({ deliveries: [hub, { destination: 'email', successful: false }] });
    expect(describeResult(partly, 'send')).toEqual({ severity: 'warning', message: 'Report CR-AAAA-BBBB-CCCC was saved, but not every delivery succeeded.' });
  });
});

describe('findHubScreenshotNotice', () => {
  const hub = { destination: 'hub', label: 'Context Reporter Hub', successful: true, externalReference: 'LW-152', externalUrl: 'https://hub.example.com/app/reports/LW-152' };

  beforeEach(() => {
    globalThis.TYPO3 = {
      lang: {
        'context_reporter.result.receivedByHub': 'Report received as %s in Context Reporter Hub.',
        'context_reporter.result.hubScreenshot.title': 'The screenshot was not stored in Context Reporter Hub.',
        'context_reporter.result.hubScreenshot.reason.storage_full': 'The storage of the Hub workspace is full. Ask the developer or agency who looks after this site.',
        'context_reporter.result.hubScreenshot.reason.other': 'The Hub did not give a known reason.',
        'context_reporter.result.hubScreenshot.kept': 'The report itself was received. The screenshot is kept with the report in TYPO3.',
      },
    };
  });

  afterEach(() => {
    delete globalThis.TYPO3;
  });

  it('reads the notice of a successful delivery to the Hub only', () => {
    const notice = { count: 1, reason: 'storage_full' };
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, screenshotNotStored: notice }] }))).toEqual({ reason: 'storage_full' });
    expect(findHubScreenshotNotice(createResult({ deliveries: [hub] }))).toBeNull();
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, screenshotNotStored: null }] }))).toBeNull();
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, successful: false, screenshotNotStored: notice }] }))).toBeNull();
    // A webhook to a Hub intake address carries the Hub's acknowledgement (installations updated from 0.3)
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, destination: 'webhook', screenshotNotStored: notice }] }))).toEqual({ reason: 'storage_full' });
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, destination: 'email', screenshotNotStored: notice }] }))).toBeNull();
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, screenshotNotStored: 'storage_full' }] }))).toBeNull();
  });

  it('treats unknown reasons generically', () => {
    const unknown = createResult({ deliveries: [{ ...hub, screenshotNotStored: { count: 1, reason: '<img src=x onerror=alert(1)>' } }] });
    expect(findHubScreenshotNotice(unknown)).toEqual({ reason: 'other' });
    expect(findHubScreenshotNotice(createResult({ deliveries: [{ ...hub, screenshotNotStored: {} }] }))).toEqual({ reason: 'other' });
  });

  it('tells the reporter that the report was received but its screenshot was not stored', () => {
    const view = render(createResult({ deliveries: [{ ...hub, screenshotNotStored: { count: 1, reason: 'storage_full' } }] }));

    // The outcome stays a success with the Hub reference
    expect(view.querySelector('.cr-result.callout-success .callout-title').textContent).toBe('Report received as LW-152 in Context Reporter Hub.');
    const notice = view.querySelector('.cr-result__screenshot-notice');
    expect(notice.classList.contains('callout-warning')).toBe(true);
    expect(notice.querySelector('.callout-title').textContent).toBe('The screenshot was not stored in Context Reporter Hub.');
    expect([...notice.querySelectorAll('p')].map((p) => p.textContent)).toEqual([
      'The storage of the Hub workspace is full. Ask the developer or agency who looks after this site.',
      'The report itself was received. The screenshot is kept with the report in TYPO3.',
    ]);
    expect(view.querySelector('a.cr-result__hub').getAttribute('href')).toBe('https://hub.example.com/app/reports/LW-152');
    expect(describeResult(createResult({ deliveries: [{ ...hub, screenshotNotStored: { count: 1, reason: 'storage_full' } }] }), 'send').severity).toBe('success');
  });

  it('shows no notice when the Hub stored the screenshot or did not say', () => {
    expect(render(createResult({ deliveries: [hub] })).querySelector('.cr-result__screenshot-notice')).toBeNull();
    expect(render(createResult({ deliveries: [{ ...hub, screenshotNotStored: null }] })).querySelector('.cr-result__screenshot-notice')).toBeNull();
  });
});
