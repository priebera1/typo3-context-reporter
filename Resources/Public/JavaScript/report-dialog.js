/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import '@typo3/backend/copy-to-clipboard.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { AjaxResponse } from '@typo3/core/ajax/ajax-response.js';
import { h, icon, uniqueId } from '@priebera/context-reporter/dom.js';
import { collectBrowserInfo, collectLocation } from '@priebera/context-reporter/environment.js';
import { label } from '@priebera/context-reporter/labels.js';
import { OPEN_EVENT } from '@priebera/context-reporter/report-request.js';
import { buildResultView, describeResult } from '@priebera/context-reporter/report-result.js';
import {
  ScreenshotError,
  canvasFromFile,
  grabFrame,
  isScreenCaptureSupported,
  requestDisplayStream,
  stopStream,
  wait,
} from '@priebera/context-reporter/screenshot/capture.js';

const SCHEMA = 'context-reporter.report.v1';
const DRAFT_LIFETIME_MS = 30 * 60 * 1000;

/**
 * Unsent title and description per report target, kept in memory only, so
 * an accidentally closed dialog does not lose what was typed.
 *
 * @type {Map<string, {title: string, description: string, savedAt: number}>}
 */
const drafts = new Map();

/** @type {ReportDialog|null} */
let activeDialog = null;

/**
 * Opens the report dialog. Only one dialog can be open at a time.
 *
 * @param {{source?: string, target?: Object}} request
 */
export function openReportDialog(request) {
  if (activeDialog !== null) {
    activeDialog.focus();
    return;
  }
  activeDialog = new ReportDialog(request ?? {});
  activeDialog.open();
}

document.addEventListener(OPEN_EVENT, (event) => {
  event.preventDefault();
  let request = {};
  try {
    request = JSON.parse(String(event.detail));
  } catch {
    // Use the defaults of a generic report
  }
  openReportDialog(request);
});

class ReportDialog {
  /**
   * @param {{source?: string, target?: Object}} request
   */
  constructor(request) {
    this.request = {
      source: typeof request.source === 'string' ? request.source : 'toolbar',
      target: request.target && typeof request.target === 'object' ? request.target : {},
    };
    this.draftKey = JSON.stringify([this.request.source, this.request.target]);
    this.state = 'loading';
    this.prepared = null;
    this.editor = null;
    this.modal = null;
    this.suspended = false;
    this.capturing = false;
    this.root = h('div', { class: 'cr-dialog' });
    this.errorBox = h('div', { class: 'alert alert-danger cr-dialog__error', role: 'alert', hidden: true });
    this.pasteListener = (event) => this.handlePaste(event);
  }

  open() {
    this.renderLoading();
    this.showModal();
    document.addEventListener('paste', this.pasteListener);
    this.prepare();
  }

  focus() {
    (this.titleInput ?? this.root).focus?.();
  }

  close() {
    this.modal?.hideModal();
  }

  showModal() {
    const modal = Modal.advanced({
      title: label('dialog.title'),
      content: this.root,
      size: Modal.sizes.large,
      staticBackdrop: true,
      additionalCssClasses: ['cr-modal'],
      buttons: this.getButtons(),
    });
    modal.addEventListener('typo3-modal-hidden', () => this.handleHidden(modal));
    modal.addEventListener('typo3-modal-shown', () => this.applyButtonStates());
    this.modal = modal;
  }

  /**
   * @param {HTMLElement} modal
   */
  handleHidden(modal) {
    if (modal !== this.modal) {
      return;
    }
    this.modal = null;
    if (this.suspended) {
      return;
    }
    if (this.state === 'form') {
      this.saveDraft();
    }
    document.removeEventListener('paste', this.pasteListener);
    this.editor?.destroy();
    this.editor = null;
    activeDialog = null;
  }

  async prepare() {
    this.setState('loading');
    this.renderLoading();
    try {
      const response = await new AjaxRequest(this.getAjaxUrl('context_reporter_prepare')).post(
        {
          source: this.request.source,
          target: this.request.target,
          location: collectLocation(window, this.getPageTreeSelection()),
          browser: collectBrowserInfo(window),
        },
        { headers: { 'Content-Type': 'application/json' } },
      );
      this.prepared = await response.resolve();
      this.renderForm();
    } catch (error) {
      this.renderFailure(await this.getErrorMessage(error));
    }
  }

  /**
   * @param {'send'|'download'} action
   */
  async submit(action) {
    if (this.state !== 'form') {
      return;
    }
    this.hideError();
    const title = this.titleInput.value.trim();
    if (title === '') {
      this.titleInput.classList.add('is-invalid');
      this.titleInput.focus();
      this.showError(label('form.title.required'));
      return;
    }
    this.setState('submitting');
    try {
      const formData = new FormData();
      formData.append('draftToken', this.prepared.draftToken);
      formData.append('title', title);
      formData.append('description', this.descriptionInput.value);
      formData.append('action', action);
      if (this.editor) {
        const image = await this.editor.exportImage(this.prepared.limits.screenshotMaxBytes);
        formData.append('screenshot', image, image.type === 'image/jpeg' ? 'screenshot.jpg' : 'screenshot.png');
      }
      const response = await new AjaxRequest(this.getAjaxUrl('context_reporter_submit')).post(formData);
      const result = await response.resolve();
      drafts.delete(this.draftKey);
      if (action === 'download') {
        this.startDownload(result.downloads.json);
      }
      this.renderResult(result, action);
    } catch (error) {
      this.setState('form');
      this.showError(await this.getErrorMessage(error));
    }
  }

  renderLoading() {
    this.root.replaceChildren(
      h('div', { class: 'cr-dialog__loading', role: 'status' },
        h('typo3-backend-spinner', { size: 'medium' }),
        h('span', {}, label('dialog.loading')),
      ),
    );
  }

  /**
   * @param {string} message
   */
  renderFailure(message) {
    this.setState('failed');
    this.root.replaceChildren(
      h('div', { class: 'callout callout-danger' },
        h('div', { class: 'callout-content' },
          h('div', { class: 'callout-title' }, label('dialog.failed')),
          h('div', { class: 'callout-body' },
            h('p', {}, message),
            h('button', { type: 'button', class: 'btn btn-default', onclick: () => this.prepare() }, label('button.retry')),
          ),
        ),
      ),
    );
  }

  renderForm() {
    const { document: contextDocument, presentation, destinations, limits } = this.prepared;
    const titleId = uniqueId('cr-title');
    const descriptionId = uniqueId('cr-description');
    const counterId = uniqueId('cr-counter');

    this.titleInput = h('input', {
      id: titleId,
      class: 'form-control',
      type: 'text',
      required: true,
      maxlength: String(limits.titleMaxLength),
      autocomplete: 'off',
      placeholder: label('form.title.placeholder'),
      oninput: () => {
        this.titleInput.classList.remove('is-invalid');
        this.updatePreview();
      },
    });
    this.counter = h('span', { id: counterId, class: 'cr-counter' });
    this.descriptionInput = h('textarea', {
      id: descriptionId,
      class: 'form-control',
      rows: '5',
      maxlength: String(limits.descriptionMaxLength),
      placeholder: label('form.description.placeholder'),
      'aria-describedby': counterId,
      oninput: () => {
        this.updateCounter();
        this.updatePreview();
      },
    });

    this.root.replaceChildren(
      this.renderSubject(presentation ?? this.createFallbackPresentation(contextDocument)),
      h('div', { class: 'form-group' },
        h('label', { class: 'form-label', for: titleId }, label('form.title'), h('span', { class: 'cr-required', 'aria-hidden': 'true' }, ' *')),
        this.titleInput,
      ),
      h('div', { class: 'form-group' },
        h('label', { class: 'form-label', for: descriptionId }, label('form.description')),
        this.descriptionInput,
        h('div', { class: 'form-text cr-dialog__hint' }, h('span', {}, label('form.description.hint')), this.counter),
      ),
      this.renderScreenshotSection(),
      this.renderTechnicalDetails(),
      this.renderDestinations(destinations),
      this.errorBox,
    );

    const draft = drafts.get(this.draftKey);
    if (draft && Date.now() - draft.savedAt < DRAFT_LIFETIME_MS) {
      this.titleInput.value = draft.title;
      this.descriptionInput.value = draft.description;
    }
    this.updateCounter();
    this.setState('form');
    this.titleInput.focus();
  }

  /**
   * The detected object as a compact card: type, name, identifier and where
   * it lives. Raw identifiers, routes and URLs are in the technical data.
   *
   * @param {{icon: string, typeLabel: string, title: string, identifier: string, location: string, meta: string}} presentation
   */
  renderSubject(presentation) {
    const titleId = uniqueId('cr-context-title');
    const identity = [
      presentation.identifier ? h('span', { class: 'cr-identifier' }, presentation.identifier) : null,
      presentation.identifier && presentation.location ? ' · ' : null,
      presentation.location || null,
    ].filter(Boolean);
    return h('section', { class: 'cr-context', 'aria-labelledby': titleId },
      h('div', { class: 'cr-context-card' },
        h('div', { class: 'cr-context-card__icon' },
          h('typo3-backend-icon', { identifier: presentation.icon || 'context-reporter-report', size: 'medium', 'aria-hidden': 'true' }),
        ),
        h('div', { class: 'cr-context-card__body' },
          h('p', { class: 'cr-context-card__type' },
            h('span', { class: 'visually-hidden' }, `${label('subject.heading')}: `),
            presentation.typeLabel,
          ),
          h('p', { class: 'cr-context-card__title', id: titleId }, presentation.title),
          identity.length > 0 ? h('p', { class: 'cr-context-card__identity' }, identity) : null,
          presentation.meta ? h('p', { class: 'cr-context-card__meta' }, presentation.meta) : null,
        ),
      ),
      h('p', { class: 'cr-context__note' }, icon('actions-info-circle-alt'), label('subject.explanation')),
    );
  }

  /**
   * @param {Object} contextDocument
   * @returns {Object}
   */
  createFallbackPresentation(contextDocument) {
    const subject = contextDocument.subject ?? {};
    return {
      icon: 'context-reporter-report',
      typeLabel: subject.typeLabel ?? '',
      title: subject.label ?? '',
      identifier: subject.table && subject.uid ? `${subject.table}:${subject.uid}` : (subject.identifier ?? ''),
      location: '',
      meta: contextDocument.summary ?? '',
    };
  }

  renderScreenshotSection() {
    this.fileInput = h('input', {
      type: 'file',
      accept: 'image/png,image/jpeg,image/webp,image/gif',
      class: 'visually-hidden',
      tabindex: '-1',
      'aria-hidden': 'true',
      onchange: () => {
        const file = this.fileInput.files?.[0];
        this.fileInput.value = '';
        if (file) {
          this.loadFile(file);
        }
      },
    });
    const captureButton = isScreenCaptureSupported()
      ? h('button', { type: 'button', class: 'btn btn-default', onclick: () => this.captureScreen() }, icon('actions-device-desktop'), ' ', label('screenshot.capture'))
      : null;
    const uploadButton = h('button', { type: 'button', class: 'btn btn-default', onclick: () => this.fileInput.click() }, icon('actions-upload'), ' ', label('screenshot.upload'));

    this.screenshotEmpty = h('div', { class: 'cr-screenshot__empty' },
      h('div', { class: 'cr-screenshot__actions' }, captureButton, uploadButton, this.fileInput),
      h('p', { class: 'form-text' }, captureButton ? label('screenshot.hint') : label('screenshot.hintNoCapture')),
    );
    this.editorHost = h('div', { class: 'cr-screenshot__editor' });
    this.screenshotStatus = h('span', { class: 'form-text', 'aria-live': 'polite' });
    this.removeScreenshotButton = h('button', {
      type: 'button',
      class: 'btn btn-default btn-sm',
      hidden: true,
      onclick: () => this.removeScreenshot(),
    }, icon('actions-delete'), ' ', label('screenshot.remove'));

    return h('fieldset', {
      class: 'cr-screenshot',
      ondragover: (event) => {
        if (Array.from(event.dataTransfer?.items ?? []).some((item) => item.kind === 'file')) {
          event.preventDefault();
        }
      },
      ondrop: (event) => {
        const file = event.dataTransfer?.files?.[0];
        if (file) {
          event.preventDefault();
          this.loadFile(file);
        }
      },
    },
    h('legend', { class: 'form-label' }, label('screenshot.legend')),
    h('p', { class: 'form-text' }, label('screenshot.privacy')),
    this.screenshotEmpty,
    this.editorHost,
    h('div', { class: 'cr-screenshot__footer' }, this.screenshotStatus, this.removeScreenshotButton),
    );
  }

  renderTechnicalDetails() {
    this.previewCode = h('code', {});
    this.details = h('details', { class: 'cr-technical', ontoggle: () => this.updatePreview() },
      h('summary', {}, label('technical.summary')),
      h('p', { class: 'form-text' }, label('technical.explanation')),
      h('pre', { class: 'cr-technical__json', tabindex: '0' }, this.previewCode),
    );
    return this.details;
  }

  /**
   * @param {Array<{identifier: string, label: string, target?: string}>} destinations
   */
  renderDestinations(destinations) {
    const names = destinations.map((destination) => (destination.target ? `${destination.label} (${destination.target})` : destination.label));
    return h('p', { class: 'cr-destinations' },
      icon('actions-info-circle'),
      ' ',
      names.length > 0 ? label('destinations.send', names.join(', ')) : label('destinations.localOnly'),
    );
  }

  /**
   * @param {Object} result
   * @param {'send'|'download'} action
   */
  renderResult(result, action) {
    this.setState('done');
    const { message } = describeResult(result, action);
    this.root.replaceChildren(...buildResultView(result, action, {
      onOpenReport: (event, url) => this.openInContentFrame(event, url),
    }));
    const failed = (result.deliveries ?? []).some((delivery) => !delivery.successful);
    const notify = failed ? Notification.warning : Notification.success;
    notify.call(Notification, label('dialog.title'), message);
  }

  async captureScreen() {
    if (this.capturing || this.state !== 'form') {
      return;
    }
    let stream;
    try {
      // Must run directly inside the click handler (user activation)
      stream = await requestDisplayStream();
    } catch (error) {
      if (error?.name !== 'NotAllowedError' && error?.name !== 'AbortError') {
        this.showError(label('screenshot.error.captureFailed'));
      }
      return;
    }
    this.capturing = true;
    let canvas = null;
    try {
      await this.suspendModal();
      canvas = await grabFrame(stream);
    } catch {
      stopStream(stream);
    } finally {
      this.resumeModal();
      this.capturing = false;
    }
    if (canvas) {
      await this.setScreenshot(canvas);
    } else {
      this.showError(label('screenshot.error.captureFailed'));
    }
  }

  /**
   * Hides the dialog so it is not part of the captured frame.
   */
  async suspendModal() {
    const modal = this.modal;
    if (!modal) {
      return;
    }
    this.suspended = true;
    const hidden = new Promise((resolve) => modal.addEventListener('typo3-modal-hidden', () => resolve(), { once: true }));
    modal.hideModal();
    await Promise.race([hidden, wait(1500)]);
    await new Promise((resolve) => window.requestAnimationFrame(() => window.requestAnimationFrame(() => resolve())));
  }

  resumeModal() {
    if (!this.suspended) {
      return;
    }
    this.suspended = false;
    if (this.modal === null) {
      this.showModal();
    }
  }

  /**
   * @param {File} file
   */
  async loadFile(file) {
    if (this.state !== 'form') {
      return;
    }
    this.hideError();
    try {
      await this.setScreenshot(await canvasFromFile(file));
    } catch (error) {
      const reason = error instanceof ScreenshotError ? error.reason : 'unsupportedFile';
      this.showError(label(`screenshot.error.${reason}`));
    }
  }

  /**
   * @param {HTMLCanvasElement} canvas
   */
  async setScreenshot(canvas) {
    const { ScreenshotEditor } = await import('@priebera/context-reporter/screenshot/editor.js');
    this.editor?.destroy();
    this.screenshotEmpty.hidden = true;
    this.removeScreenshotButton.hidden = false;
    this.editor = new ScreenshotEditor(this.editorHost, canvas, { onChange: () => this.handleScreenshotChange() });
    this.handleScreenshotChange();
    this.editorHost.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
  }

  removeScreenshot() {
    this.editor?.destroy();
    this.editor = null;
    this.screenshotEmpty.hidden = false;
    this.removeScreenshotButton.hidden = true;
    this.handleScreenshotChange();
  }

  handleScreenshotChange() {
    const info = this.editor?.describe();
    this.screenshotStatus.textContent = info ? label('screenshot.status', `${info.width}×${info.height}`, info.annotations) : '';
    this.updatePreview();
  }

  /**
   * @param {ClipboardEvent} event
   */
  handlePaste(event) {
    if (this.state !== 'form' || this.modal === null) {
      return;
    }
    const item = Array.from(event.clipboardData?.items ?? []).find((candidate) => candidate.kind === 'file' && candidate.type.startsWith('image/'));
    const file = item?.getAsFile();
    if (file) {
      event.preventDefault();
      this.loadFile(file);
    }
  }

  updatePreview() {
    if (!this.details?.open || !this.prepared) {
      return;
    }
    this.previewCode.textContent = JSON.stringify(this.buildPreview(), null, 2);
  }

  /**
   * The report as it will be stored and sent. Only the identifier, the time
   * and the image bytes are added by the server.
   */
  buildPreview() {
    const contextDocument = this.prepared.document;
    const placeholder = label('technical.assignedOnSubmit');
    const preview = {
      schema: SCHEMA,
      id: placeholder,
      createdAt: placeholder,
      source: this.prepared.source,
      title: this.titleInput.value.trim(),
      description: this.descriptionInput.value.trim(),
      summary: contextDocument.summary ?? '',
      subject: contextDocument.subject ?? {},
    };
    for (const section of ['project', 'reporter']) {
      if (contextDocument[section]) {
        preview[section] = contextDocument[section];
      }
    }
    preview.context = contextDocument.context ?? {};
    for (const section of ['system', 'browser']) {
      if (contextDocument[section]) {
        preview[section] = contextDocument[section];
      }
    }
    const info = this.editor?.describe();
    preview.attachments = info
      ? [{ type: 'screenshot', mediaType: 'image/png', width: info.width, height: info.height, content: label('technical.screenshotContent') }]
      : [];
    return preview;
  }

  updateCounter() {
    const max = this.prepared.limits.descriptionMaxLength;
    this.counter.textContent = label('form.description.counter', this.descriptionInput.value.length, max);
  }

  saveDraft() {
    const title = this.titleInput?.value ?? '';
    const description = this.descriptionInput?.value ?? '';
    if (title.trim() === '' && description.trim() === '') {
      drafts.delete(this.draftKey);
      return;
    }
    drafts.set(this.draftKey, { title, description, savedAt: Date.now() });
  }

  /**
   * @param {'loading'|'form'|'submitting'|'done'|'failed'} state
   */
  setState(state) {
    this.state = state;
    this.root.classList.toggle('cr-dialog--busy', state === 'submitting');
    this.root.setAttribute('aria-busy', String(state === 'submitting' || state === 'loading'));
    for (const element of this.root.querySelectorAll('.cr-screenshot button, input, textarea')) {
      element.disabled = state === 'submitting';
    }
    if (this.modal) {
      // Results and errors are short, the form needs the large dialog
      this.modal.size = state === 'done' || state === 'failed' ? Modal.sizes.default : Modal.sizes.large;
      this.modal.buttons = this.getButtons();
      this.modal.updateComplete?.then(() => this.applyButtonStates());
    }
  }

  getButtons() {
    if (this.state === 'done' || this.state === 'failed') {
      return [{ text: label('button.close'), name: 'close', btnClass: 'btn-default', active: true, trigger: () => this.close() }];
    }
    const sends = (this.prepared?.destinations ?? []).length > 0;
    return [
      { text: label('button.cancel'), name: 'cancel', btnClass: 'btn-default', trigger: () => this.close() },
      { text: label('button.download'), name: 'download', icon: 'actions-download', btnClass: 'btn-default', trigger: () => this.submit('download') },
      { text: label(sends ? 'button.send' : 'button.save'), name: 'send', icon: 'actions-check', btnClass: 'btn-primary', trigger: () => this.submit('send') },
    ];
  }

  applyButtonStates() {
    const disabled = this.state !== 'form';
    for (const name of ['download', 'send']) {
      const button = this.modal?.querySelector(`.modal-footer button[name="${name}"]`);
      if (button) {
        button.disabled = disabled;
      }
    }
  }

  /**
   * @param {string} message
   */
  showError(message) {
    this.errorBox.textContent = message;
    this.errorBox.hidden = false;
    this.errorBox.scrollIntoView?.({ block: 'nearest' });
  }

  hideError() {
    this.errorBox.hidden = true;
    this.errorBox.textContent = '';
  }

  /**
   * @param {string} url
   */
  startDownload(url) {
    const link = h('a', { href: url, download: '', hidden: true });
    document.body.append(link);
    link.click();
    link.remove();
  }

  /**
   * @param {MouseEvent} event
   * @param {string} url
   */
  openInContentFrame(event, url) {
    const container = globalThis.TYPO3?.Backend?.ContentContainer;
    if (typeof container?.setUrl !== 'function') {
      return;
    }
    event.preventDefault();
    container.setUrl(url);
    this.close();
  }

  /**
   * @param {unknown} error
   * @returns {Promise<string>}
   */
  async getErrorMessage(error) {
    if (error instanceof AjaxResponse) {
      try {
        const data = await error.resolve();
        if (typeof data?.error?.message === 'string' && data.error.message !== '') {
          return data.error.message;
        }
      } catch {
        // Not a JSON error response
      }
      return label('error.http', error.response.status);
    }
    if (error instanceof Error && /too large/i.test(error.message)) {
      return label('screenshot.error.fileTooLarge');
    }
    return label('error.unexpected');
  }

  /**
   * @param {string} name
   * @returns {string}
   */
  getAjaxUrl(name) {
    const url = globalThis.TYPO3?.settings?.ajaxUrls?.[name];
    if (typeof url !== 'string') {
      throw new Error(`Missing AJAX route ${name}`);
    }
    return url;
  }

  /**
   * @returns {string}
   */
  getPageTreeSelection() {
    try {
      return String(ModuleStateStorage.current('web').identifier ?? '');
    } catch {
      return '';
    }
  }
}
