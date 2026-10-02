// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildFindings } from '../../Resources/Public/JavaScript/findings.js';

const findings = {
  title: 'Findings',
  groups: [
    {
      key: 'website',
      title: 'Website address',
      icon: 'actions-globe',
      items: ['The website address is in "Français", which is disabled in the site configuration'],
      more: 0,
      moreText: '',
    },
    {
      key: 'files',
      title: 'Files',
      icon: 'actions-file',
      items: ['Images: "<b>team</b>.jpg" – Reference hidden', 'Images: Referenced file no longer exists'],
      more: 3,
      moreText: '3 more in the technical details',
    },
  ],
  note: 'These are settings and permissions stored in TYPO3, not a check of the website.',
};

describe('buildFindings', () => {
  it('renders one labelled list per group, in the given order, with one note', () => {
    const section = buildFindings(findings);
    document.body.replaceChildren(section);

    const title = section.querySelector('.cr-findings__title');
    expect(section.getAttribute('aria-labelledby')).toBe(title.id);
    expect(title.textContent).toBe('Findings');
    const groups = [...section.querySelectorAll('.cr-findings__group')];
    expect(groups.map((group) => group.dataset.group)).toEqual(['website', 'files']);
    for (const group of groups) {
      expect(group.getAttribute('aria-labelledby')).toBe(group.querySelector('.cr-findings__group-title').id);
    }
    expect(groups[0].querySelector('.cr-findings__group-title').textContent).toBe('Website address');
    expect(groups[0].querySelector('typo3-backend-icon').getAttribute('identifier')).toBe('actions-globe');
    expect([...groups[1].querySelectorAll('li')].map((item) => item.textContent)).toEqual(findings.groups[1].items);
    expect(section.querySelectorAll('.cr-findings__note')).toHaveLength(1);
    expect(section.querySelector('.cr-findings__note').textContent).toBe(findings.note);
  });

  it('points to the technical details for further findings of a group', () => {
    const section = buildFindings(findings);
    const [website, files] = section.querySelectorAll('.cr-findings__group');

    expect(website.querySelector('.cr-findings__more')).toBeNull();
    expect(files.querySelector('.cr-findings__more').textContent).toBe('3 more in the technical details');
  });

  it('shows findings as text, never as HTML', () => {
    const section = buildFindings(findings);

    expect(section.querySelector('b')).toBeNull();
    expect(section.textContent).toContain('"<b>team</b>.jpg"');
  });

  it('renders nothing without findings', () => {
    expect(buildFindings(null)).toBeNull();
    expect(buildFindings(undefined)).toBeNull();
    expect(buildFindings({ title: 'Findings', groups: [], note: '' })).toBeNull();
    expect(buildFindings({ title: 'Findings', groups: [{ key: 'files', title: 'Files', items: ['', 42] }], note: '' })).toBeNull();
  });

  it('works without note, icon and more text', () => {
    const section = buildFindings({ title: 'Findings', groups: [{ key: 'access', title: 'Permissions', items: ['The storage is not writable'] }] });

    expect(section.querySelector('.cr-findings__note')).toBeNull();
    expect(section.querySelector('.cr-findings__more')).toBeNull();
    expect(section.querySelectorAll('li')).toHaveLength(1);
  });
});
