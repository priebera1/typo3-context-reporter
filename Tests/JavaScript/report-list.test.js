// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import { findRowLink } from '../../Resources/Public/JavaScript/report-list.js';

describe('findRowLink', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <table><tbody>
        <tr data-context-reporter-row>
          <td><a class="report" href="/typo3/report?1">Title</a></td>
          <td class="plain"><span class="inner">Context</span></td>
          <td><button type="button" class="btn">Action</button><label><input type="checkbox"></label></td>
        </tr>
        <tr class="other"><td class="plain-other">No link</td></tr>
      </tbody></table>`;
  });

  const click = (selector, init = {}) => {
    const target = document.querySelector(selector);
    return { target, button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, defaultPrevented: false, ...init };
  };

  it('returns the report link for clicks on non-interactive parts of the row', () => {
    expect(findRowLink(click('.plain'))?.getAttribute('href')).toBe('/typo3/report?1');
    expect(findRowLink(click('.inner'))?.getAttribute('href')).toBe('/typo3/report?1');
  });

  it('leaves links and controls alone', () => {
    expect(findRowLink(click('a.report'))).toBeNull();
    expect(findRowLink(click('button.btn'))).toBeNull();
    expect(findRowLink(click('input'))).toBeNull();
  });

  it('ignores modified, secondary and handled clicks', () => {
    expect(findRowLink(click('.plain', { ctrlKey: true }))).toBeNull();
    expect(findRowLink(click('.plain', { metaKey: true }))).toBeNull();
    expect(findRowLink(click('.plain', { button: 1 }))).toBeNull();
    expect(findRowLink(click('.plain', { defaultPrevented: true }))).toBeNull();
  });

  it('ignores rows without a report link', () => {
    expect(findRowLink(click('.plain-other'))).toBeNull();
  });

  it('ignores clicks that end a text selection', () => {
    const cell = document.querySelector('.plain');
    const range = document.createRange();
    range.selectNodeContents(cell);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    expect(findRowLink(click('.plain'))).toBeNull();
    window.getSelection().removeAllRanges();
  });
});
