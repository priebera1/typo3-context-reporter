// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildVisibilityNotices } from '../../Resources/Public/JavaScript/visibility-notices.js';

const visibility = {
  title: 'Visibility settings',
  notices: ['Hidden', 'Frontend access: <b>Members</b>', 'Not translated into: Deutsch'],
  note: 'These are the settings stored in TYPO3.',
};

describe('buildVisibilityNotices', () => {
  it('renders the notices as a labelled list with the note', () => {
    const section = buildVisibilityNotices(visibility);
    document.body.replaceChildren(section);

    const title = section.querySelector('.cr-visibility__title');
    expect(section.getAttribute('aria-labelledby')).toBe(title.id);
    expect(title.textContent).toBe('Visibility settings');
    expect([...section.querySelectorAll('.cr-visibility__list li')].map((item) => item.textContent)).toEqual(visibility.notices);
    expect(section.querySelector('.cr-visibility__note').textContent).toBe('These are the settings stored in TYPO3.');
  });

  it('shows notices as text, never as HTML', () => {
    const section = buildVisibilityNotices(visibility);

    expect(section.querySelector('b')).toBeNull();
    expect(section.textContent).toContain('Frontend access: <b>Members</b>');
  });

  it('renders nothing without notices', () => {
    expect(buildVisibilityNotices(null)).toBeNull();
    expect(buildVisibilityNotices(undefined)).toBeNull();
    expect(buildVisibilityNotices({ title: 'Visibility settings', notices: [], note: '' })).toBeNull();
    expect(buildVisibilityNotices({ title: 'Visibility settings', notices: ['', 42], note: '' })).toBeNull();
  });

  it('works without a note', () => {
    const section = buildVisibilityNotices({ title: 'Visibility settings', notices: ['Hidden'] });

    expect(section.querySelector('.cr-visibility__note')).toBeNull();
    expect(section.querySelectorAll('li')).toHaveLength(1);
  });
});
