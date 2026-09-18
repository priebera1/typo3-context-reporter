/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

/**
 * Longest edge of a screenshot. Larger captures (e.g. 4K displays) are scaled
 * down to keep uploads and emails reasonably small.
 */
export const MAX_EDGE = 2560;

const MAX_INPUT_FILE_BYTES = 40 * 1024 * 1024;
const SUPPORTED_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/bmp'];

export class ScreenshotError extends Error {
  /**
   * @param {string} reason
   */
  constructor(reason) {
    super(reason);
    this.reason = reason;
  }
}

/**
 * The Screen Capture API is only available in secure contexts and not on
 * most mobile browsers.
 *
 * @returns {boolean}
 */
export function isScreenCaptureSupported() {
  return Boolean(window.isSecureContext && navigator.mediaDevices && typeof navigator.mediaDevices.getDisplayMedia === 'function');
}

/**
 * Asks the browser for a capture stream. The browser shows its own picker,
 * so nothing is captured without the user's explicit choice.
 * Must be called directly from a user gesture.
 *
 * @returns {Promise<MediaStream>}
 */
export function requestDisplayStream() {
  return navigator.mediaDevices.getDisplayMedia({
    video: { displaySurface: 'browser' },
    audio: false,
    preferCurrentTab: true,
    selfBrowserSurface: 'include',
    surfaceSwitching: 'exclude',
    monitorTypeSurfaces: 'include',
  });
}

/**
 * @param {MediaStream|null|undefined} stream
 */
export function stopStream(stream) {
  stream?.getTracks().forEach((track) => track.stop());
}

/**
 * Takes a single frame from the capture stream and stops the stream.
 *
 * @param {MediaStream} stream
 * @param {number} settleMilliseconds Time for the page to repaint (e.g. after hiding the dialog)
 * @returns {Promise<HTMLCanvasElement>}
 */
export async function grabFrame(stream, settleMilliseconds = 400) {
  const video = document.createElement('video');
  video.muted = true;
  video.playsInline = true;
  video.srcObject = stream;
  try {
    await video.play();
    await wait(settleMilliseconds);
    if (typeof video.requestVideoFrameCallback === 'function') {
      await Promise.race([
        new Promise((resolve) => video.requestVideoFrameCallback(() => resolve())),
        wait(300),
      ]);
    }
    if (!video.videoWidth || !video.videoHeight) {
      throw new ScreenshotError('captureFailed');
    }
    return drawScaled(video, video.videoWidth, video.videoHeight);
  } finally {
    stopStream(stream);
    video.srcObject = null;
  }
}

/**
 * Loads an image file (upload, paste, drop) into a canvas. Decoding happens
 * locally; the file itself is never uploaded, only the edited result.
 *
 * @param {File|Blob|null} file
 * @returns {Promise<HTMLCanvasElement>}
 */
export async function canvasFromFile(file) {
  if (!file || !SUPPORTED_TYPES.includes(file.type)) {
    throw new ScreenshotError('unsupportedFile');
  }
  if (file.size > MAX_INPUT_FILE_BYTES) {
    throw new ScreenshotError('fileTooLarge');
  }
  let bitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    throw new ScreenshotError('unsupportedFile');
  }
  try {
    return drawScaled(bitmap, bitmap.width, bitmap.height);
  } finally {
    bitmap.close?.();
  }
}

/**
 * @param {CanvasImageSource} source
 * @param {number} width
 * @param {number} height
 * @returns {HTMLCanvasElement}
 */
function drawScaled(source, width, height) {
  const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(width * scale));
  canvas.height = Math.max(1, Math.round(height * scale));
  const context = canvas.getContext('2d');
  context.imageSmoothingQuality = 'high';
  context.drawImage(source, 0, 0, canvas.width, canvas.height);
  return canvas;
}

/**
 * @param {number} milliseconds
 * @returns {Promise<void>}
 */
export function wait(milliseconds) {
  return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}
