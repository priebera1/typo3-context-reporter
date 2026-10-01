// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildFileCheckNotices } from '../../Resources/Public/JavaScript/file-check-notices.js';

const fileChecks = {
  title: 'File checks',
  notices: ['Images: "<b>team</b>.jpg" – Reference hidden', 'Referenced files not checked (outside the accessible file mounts): 2'],
  note: 'Based on the TYPO3 file index.',
};

describe('buildFileCheckNotices', () => {
  it('renders the notices as a labelled list with the note', () => {
    const section = buildFileCheckNotices(fileChecks);
    document.body.replaceChildren(section);

    const title = section.querySelector('.cr-file-checks__title');
    expect(section.classList.contains('cr-file-checks')).toBe(true);
    expect(section.getAttribute('aria-labelledby')).toBe(title.id);
    expect(title.textContent).toBe('File checks');
    expect([...section.querySelectorAll('.cr-file-checks__list li')].map((item) => item.textContent)).toEqual(fileChecks.notices);
    expect(section.querySelector('.cr-file-checks__note').textContent).toBe('Based on the TYPO3 file index.');
  });

  it('shows file names as text, never as HTML', () => {
    const section = buildFileCheckNotices(fileChecks);

    expect(section.querySelector('b')).toBeNull();
    expect(section.textContent).toContain('"<b>team</b>.jpg"');
  });

  it('renders nothing without notices, e.g. for reports created before the file checks', () => {
    expect(buildFileCheckNotices(null)).toBeNull();
    expect(buildFileCheckNotices(undefined)).toBeNull();
    expect(buildFileCheckNotices({ title: 'File checks', notices: [], note: '' })).toBeNull();
    expect(buildFileCheckNotices({ title: 'File checks', notices: [null, ''], note: '' })).toBeNull();
  });
});
