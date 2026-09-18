import { describe, expect, it } from 'vitest';
import {
  COLORS,
  MAX_POINTS,
  TOOLS,
  appendPoint,
  findToolByKey,
  getColor,
  pathLength,
} from '../../Resources/Public/JavaScript/screenshot/annotation-tools.js';

const luminance = (hex) => {
  const channels = hex.match(/[0-9a-f]{2}/gi).map((value) => {
    const channel = parseInt(value, 16) / 255;
    return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};
const contrast = (a, b) => {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (light + 0.05) / (dark + 0.05);
};

describe('annotation tools', () => {
  it('offers a fixed palette of five colors', () => {
    expect(COLORS.map((color) => color.id)).toEqual(['red', 'orange', 'green', 'blue', 'black']);
    expect(getColor('#2563eb')?.id).toBe('blue');
    expect(getColor('#123456')).toBeUndefined();
  });

  it('writes text labels in a readable color on every palette color', () => {
    for (const color of COLORS) {
      expect(contrast(color.value, color.text), color.id).toBeGreaterThanOrEqual(4.5);
    }
  });

  it('offers a draw tool and a unique shortcut per tool', () => {
    expect(TOOLS.map((tool) => tool.id)).toEqual(['select', 'rectangle', 'arrow', 'draw', 'text', 'redact']);
    expect(new Set(TOOLS.map((tool) => tool.key)).size).toBe(TOOLS.length);
    expect(findToolByKey('d')?.id).toBe('draw');
    expect(findToolByKey('D')?.id).toBe('draw');
    expect(findToolByKey('x')).toBeUndefined();
  });
});

describe('freehand paths', () => {
  it('skips points that are too close to the previous one', () => {
    const points = [10, 10];

    expect(appendPoint(points, 11, 11, 3)).toBe(false);
    expect(appendPoint(points, 13, 10, 3)).toBe(true);
    expect(appendPoint(points, 13, 14, 3)).toBe(true);

    expect(points).toEqual([10, 10, 13, 10, 13, 14]);
  });

  it('starts with the first point and stops growing at the limit', () => {
    const points = [];
    expect(appendPoint(points, 5, 5, 3)).toBe(true);
    expect(points).toEqual([5, 5]);

    const full = Array.from({ length: MAX_POINTS * 2 }, (_, index) => index * 10);
    expect(appendPoint(full, 1e6, 1e6, 3)).toBe(false);
    expect(full).toHaveLength(MAX_POINTS * 2);
  });

  it('measures the length of a path', () => {
    expect(pathLength([0, 0, 3, 4, 3, 10])).toBe(11);
    expect(pathLength([7, 7])).toBe(0);
    expect(pathLength([])).toBe(0);
  });
});
