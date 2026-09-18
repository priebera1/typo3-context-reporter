import { readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { COLORS, TOOLS } from '../../Resources/Public/JavaScript/screenshot/annotation-tools.js';

const javaScriptDirectory = new URL('../../Resources/Public/JavaScript/', import.meta.url);
const labelFile = new URL('../../Resources/Private/Language/locallang_js.xlf', import.meta.url);

const knownKeys = new Set(
  [...readFileSync(labelFile, 'utf8').matchAll(/<trans-unit id="context_reporter\.([^"]+)"/g)].map((match) => match[1]),
);

function readSources() {
  return readdirSync(javaScriptDirectory, { recursive: true })
    .filter((file) => String(file).endsWith('.js') && !String(file).startsWith('contrib'))
    .map((file) => readFileSync(new URL(String(file), javaScriptDirectory), 'utf8'))
    .join('\n');
}

describe('locallang_js.xlf', () => {
  it('contains every label key used literally in the JavaScript modules', () => {
    const sources = readSources();
    const used = new Set([
      ...[...sources.matchAll(/\blabel\(\s*'([^']+)'/g)].map((match) => match[1]),
      ...[...sources.matchAll(/\blabel: '([^']+)'/g)].map((match) => match[1]),
    ]);

    expect(used.size).toBeGreaterThan(40);
    expect([...used].filter((key) => !knownKeys.has(key))).toEqual([]);
  });

  it('contains the label keys that the modules build dynamically', () => {
    const reasons = [...readSources().matchAll(/new ScreenshotError\('([^']+)'\)/g)].map((match) => match[1]);
    const dynamic = [
      ...['summary', 'markdown', 'json', 'link'].map((key) => `result.copy.${key}`),
      ...reasons.map((reason) => `screenshot.error.${reason}`),
      ...TOOLS.map((tool) => `editor.tool.${tool.id}`),
      ...COLORS.map((color) => `editor.color.${color.id}`),
    ];

    expect(reasons.length).toBeGreaterThan(0);
    expect(dynamic.filter((key) => !knownKeys.has(key))).toEqual([]);
  });
});
