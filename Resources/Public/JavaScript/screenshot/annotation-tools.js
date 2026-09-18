/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

/**
 * Tools of the screenshot editor with their icon and keyboard shortcut.
 */
export const TOOLS = [
  { id: 'select', icon: 'actions-hand-pointer', key: 'v' },
  { id: 'rectangle', icon: 'actions-square', key: 'r' },
  { id: 'arrow', icon: 'actions-arrow-right-up', key: 'a' },
  { id: 'draw', icon: 'actions-pencil', key: 'd' },
  { id: 'text', icon: 'actions-comment', key: 't' },
  { id: 'redact', icon: 'actions-marker', key: 'b' },
];

/**
 * The fixed annotation palette. "text" is the color of note text on a
 * label of that color (contrast of at least 4.5:1).
 */
export const COLORS = [
  { id: 'red', value: '#e5231b', text: '#ffffff' },
  { id: 'orange', value: '#f97316', text: '#111111' },
  { id: 'green', value: '#16a34a', text: '#111111' },
  { id: 'blue', value: '#2563eb', text: '#ffffff' },
  { id: 'black', value: '#111111', text: '#ffffff' },
];

/**
 * Freehand strokes are limited so an accidental scribble cannot produce a huge undo history.
 */
export const MAX_POINTS = 2000;

/**
 * @param {string} key
 * @returns {{id: string, icon: string, key: string}|undefined}
 */
export function findToolByKey(key) {
  const normalized = key.toLowerCase();
  return TOOLS.find((tool) => tool.key === normalized);
}

/**
 * @param {string} value
 * @returns {{id: string, value: string, text: string}|undefined}
 */
export function getColor(value) {
  return COLORS.find((color) => color.value === value);
}

/**
 * Adds a point to a flat [x1, y1, x2, y2, …] list unless it is closer than
 * minDistance to the previous point or the list is full.
 *
 * @param {number[]} points
 * @param {number} x
 * @param {number} y
 * @param {number} minDistance
 * @returns {boolean} Whether the point was added
 */
export function appendPoint(points, x, y, minDistance) {
  if (points.length >= MAX_POINTS * 2) {
    return false;
  }
  const length = points.length;
  if (length >= 2 && Math.hypot(x - points[length - 2], y - points[length - 1]) < minDistance) {
    return false;
  }
  points.push(x, y);
  return true;
}

/**
 * @param {number[]} points Flat [x1, y1, x2, y2, …] list
 * @returns {number}
 */
export function pathLength(points) {
  let length = 0;
  for (let index = 2; index + 1 < points.length; index += 2) {
    length += Math.hypot(points[index] - points[index - 2], points[index + 1] - points[index - 1]);
  }
  return length;
}
