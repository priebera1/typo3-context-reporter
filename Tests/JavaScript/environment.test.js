import { describe, expect, it } from 'vitest';
import { collectBrowserInfo, collectLocation, sanitizeBackendUrl } from '../../Resources/Public/JavaScript/environment.js';

const origin = 'https://backend.example.com';

function fakeDocument(moduleName = '') {
  return {
    documentElement: { dataset: {} },
    querySelector: (selector) => (moduleName !== '' && selector === '.module[data-module-name]' ? { dataset: { moduleName } } : null),
  };
}

function fakeWindow({ href = `${origin}/typo3/main`, moduleName = '', backend = undefined, currentModule = undefined } = {}) {
  return {
    location: { href, origin },
    document: fakeDocument(moduleName),
    TYPO3: {
      Backend: backend,
      ModuleMenu: currentModule === undefined ? undefined : { App: { getCurrentModule: () => currentModule } },
    },
  };
}

describe('sanitizeBackendUrl', () => {
  it('drops tokens and return URLs but keeps other parameters', () => {
    const href = '/typo3/record/edit?token=secret&edit%5Btt_content%5D%5B10%5D=edit&returnUrl=%2Ftypo3%2Fmodule%2Fweb%2Flayout%3Ftoken%3Dother&redirect=x&redirectParams=y';
    expect(sanitizeBackendUrl(href, origin)).toBe('/typo3/record/edit?edit%5Btt_content%5D%5B10%5D=edit');
  });

  it('never returns the origin of the URL', () => {
    expect(sanitizeBackendUrl('https://user:password@other.example.com/typo3/module/web/layout?id=3#frag', origin)).toBe('/typo3/module/web/layout?id=3');
  });

  it('returns an empty string for invalid URLs', () => {
    expect(sanitizeBackendUrl('http://[', origin)).toBe('');
  });
});

describe('collectLocation', () => {
  it('describes the document in the module content frame', () => {
    const content = fakeWindow({ href: `${origin}/typo3/module/web/layout?token=abc&id=2`, moduleName: 'web_layout' });
    const win = fakeWindow({ backend: { ContentContainer: { get: () => content } }, currentModule: 'web_layout' });
    expect(collectLocation(win, '2')).toEqual({
      url: '/typo3/module/web/layout?id=2',
      module: 'web_layout',
      activeModule: 'web_layout',
      pageTreeSelection: '2',
    });
  });

  it('uses the current window outside of the backend frame set', () => {
    const win = fakeWindow({ href: `${origin}/typo3/record/edit?edit%5Bpages%5D%5B1%5D=edit&token=abc` });
    expect(collectLocation(win, '0_1')).toEqual({
      url: '/typo3/record/edit?edit%5Bpages%5D%5B1%5D=edit',
      module: '',
      activeModule: '',
      pageTreeSelection: '',
    });
  });

  it('keeps the active module when the content frame is not accessible', () => {
    const blocked = {};
    Object.defineProperty(blocked, 'document', { get: () => { throw new Error('SecurityError'); } });
    const win = fakeWindow({ backend: { ContentContainer: { get: () => blocked } }, currentModule: 'file_FilelistList' });
    expect(collectLocation(win)).toEqual({ url: '', module: '', activeModule: 'file_FilelistList', pageTreeSelection: '' });
  });
});

describe('collectBrowserInfo', () => {
  it('collects a fixed set of browser facts only', () => {
    const win = {
      navigator: {
        userAgent: 'Mozilla/5.0 Test',
        userAgentData: { platform: 'macOS', mobile: false, brands: [{ brand: 'Secret', version: '1' }] },
        maxTouchPoints: 0,
        language: 'de-DE',
        languages: ['de-DE', 'de', 'en', 'fr', 'sk', 'cs'],
        cookieEnabled: true,
      },
      innerWidth: 1440,
      innerHeight: 900,
      screen: { width: 2560, height: 1440 },
      devicePixelRatio: 2,
      matchMedia: (query) => ({ matches: query === '(prefers-color-scheme: dark)' }),
      document: { documentElement: { dataset: { colorScheme: 'dark' } }, cookie: 'be_typo_user=secret' },
      localStorage: { secret: 'value' },
    };
    const info = collectBrowserInfo(win);
    expect(Object.keys(info).sort()).toEqual([
      'backendColorScheme', 'colorScheme', 'devicePixelRatio', 'language', 'languages', 'mobile',
      'platform', 'reducedMotion', 'screen', 'timeZone', 'touch', 'userAgent', 'viewport',
    ]);
    expect(info).toMatchObject({
      platform: 'macOS',
      mobile: false,
      touch: false,
      languages: ['de-DE', 'de', 'en', 'fr', 'sk'],
      viewport: { width: 1440, height: 900 },
      colorScheme: 'dark',
      backendColorScheme: 'dark',
      reducedMotion: false,
    });
    expect(JSON.stringify(info)).not.toContain('secret');
  });
});
