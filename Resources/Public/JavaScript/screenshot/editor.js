/*
 * This file is part of the TYPO3 extension "context_reporter".
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */
import Konva from '@priebera/context-reporter/contrib/konva.js';
import { h, icon } from '@priebera/context-reporter/dom.js';
import { label } from '@priebera/context-reporter/labels.js';
import { COLORS, TOOLS, appendPoint, findToolByKey, getColor, pathLength } from '@priebera/context-reporter/screenshot/annotation-tools.js';

const ANNOTATION = 'annotation';
const REDACTION_FILL = '#000000';
const MIN_SHAPE_SIZE = 4;
const MIN_POINT_DISTANCE = 2;
const MAX_TEXT_LENGTH = 200;
const MAX_HISTORY = 100;
const RESIZE_ANCHORS = ['top-left', 'top-center', 'top-right', 'middle-right', 'middle-left', 'bottom-left', 'bottom-center', 'bottom-right'];

/**
 * Annotates a screenshot locally in the browser: rectangles, arrows,
 * freehand strokes, text and opaque redaction boxes, with undo and redo.
 * Existing annotations can be selected with every tool, then moved,
 * resized, recolored or deleted; texts can be edited.
 *
 * The exported image is always a single flattened bitmap. Redacted areas are
 * painted over in the exported pixels; the original image never leaves the
 * browser.
 */
export class ScreenshotEditor {
  /**
   * @param {HTMLElement} host
   * @param {HTMLCanvasElement} source
   * @param {{onChange?: function(): void}} options
   */
  constructor(host, source, options = {}) {
    this.host = host;
    this.source = source;
    this.onChange = options.onChange ?? (() => {});
    this.tool = 'rectangle';
    this.color = COLORS[0].value;
    this.history = [];
    this.future = [];
    this.drawing = null;
    this.textInput = null;
    /** A note pressed with the text tool: edited on release unless it was dragged */
    this.pendingEdit = null;
    this.scale = 1;

    const shortEdge = Math.min(source.width, source.height);
    this.strokeWidth = Math.max(3, Math.round(shortEdge / 220));
    this.fontSize = Math.max(14, Math.round(shortEdge / 45));

    this.buildInterface();
    this.buildStage();
    this.commit();
  }

  /**
   * Renders the annotated screenshot into one image. PNG keeps text crisp;
   * JPEG is used only when the PNG would exceed the size limit.
   *
   * @param {number} maxBytes
   * @returns {Promise<Blob>}
   */
  async exportImage(maxBytes) {
    this.finishText();
    this.select(null);
    this.uiLayer.hide();
    try {
      const pixelRatio = 1 / this.scale;
      const attempts = [
        { mimeType: 'image/png', pixelRatio },
        { mimeType: 'image/jpeg', quality: 0.9, pixelRatio },
        { mimeType: 'image/jpeg', quality: 0.8, pixelRatio: pixelRatio * 0.75 },
        { mimeType: 'image/jpeg', quality: 0.7, pixelRatio: pixelRatio * 0.5 },
      ];
      let blob = null;
      for (const attempt of attempts) {
        blob = await this.stage.toBlob(attempt);
        if (blob instanceof Blob && blob.size <= maxBytes) {
          return blob;
        }
      }
      throw new Error('The screenshot is too large.');
    } finally {
      this.uiLayer.show();
    }
  }

  /**
   * @returns {{width: number, height: number, annotations: number}}
   */
  describe() {
    return {
      width: this.source.width,
      height: this.source.height,
      annotations: this.annotationLayer.getChildren().length,
    };
  }

  destroy() {
    this.resizeObserver?.disconnect();
    this.stage?.destroy();
    this.host.replaceChildren();
  }

  buildInterface() {
    this.toolButtons = new Map();
    const tools = TOOLS.map((tool) => {
      const button = h('button', {
        type: 'button',
        class: 'btn btn-default btn-sm',
        title: `${label(`editor.tool.${tool.id}`)} (${tool.key.toUpperCase()})`,
        'aria-pressed': 'false',
        onclick: () => this.setTool(tool.id),
      }, icon(tool.icon), h('span', { class: 'cr-editor__button-label' }, label(`editor.tool.${tool.id}`)));
      this.toolButtons.set(tool.id, button);
      return button;
    });

    this.colorButtons = new Map();
    const colors = COLORS.map((color) => {
      const button = h('button', {
        type: 'button',
        class: 'btn btn-default btn-sm cr-editor__color',
        title: label(`editor.color.${color.id}`),
        'aria-label': label(`editor.color.${color.id}`),
        'aria-pressed': 'false',
        onclick: () => this.setColor(color.value),
      }, h('span', { class: 'cr-editor__swatch', style: `background-color: ${color.value}` }));
      this.colorButtons.set(color.value, button);
      return button;
    });

    this.undoButton = h('button', { type: 'button', class: 'btn btn-default btn-sm', title: `${label('editor.undo')} (Ctrl+Z)`, onclick: () => this.undo() }, icon('actions-undo'), h('span', { class: 'visually-hidden' }, label('editor.undo')));
    this.redoButton = h('button', { type: 'button', class: 'btn btn-default btn-sm', title: `${label('editor.redo')} (Ctrl+Shift+Z)`, onclick: () => this.redo() }, icon('actions-redo'), h('span', { class: 'visually-hidden' }, label('editor.redo')));
    this.deleteButton = h('button', { type: 'button', class: 'btn btn-default btn-sm', title: `${label('editor.deleteSelection')} (Del)`, onclick: () => this.deleteSelection() }, icon('actions-delete'), h('span', { class: 'visually-hidden' }, label('editor.deleteSelection')));

    this.toolbar = h('div', { class: 'cr-editor__toolbar', role: 'toolbar', 'aria-label': label('editor.toolbar') },
      h('div', { class: 'btn-group', role: 'group' }, tools),
      h('div', { class: 'btn-group', role: 'group', 'aria-label': label('editor.colors') }, colors),
      h('div', { class: 'btn-group', role: 'group' }, this.undoButton, this.redoButton, this.deleteButton),
    );
    this.stageHost = h('div', {
      class: 'cr-editor__stage',
      tabindex: '0',
      role: 'img',
      'aria-label': label('editor.canvas'),
      onkeydown: (event) => this.handleKeydown(event),
    });
    this.viewport = h('div', { class: 'cr-editor__viewport' }, this.stageHost);
    this.hint = h('p', { class: 'form-text cr-editor__hint' }, label('editor.hint'));
    this.host.replaceChildren(this.toolbar, this.viewport, this.hint);
    this.setTool(this.tool);
    this.setColor(this.color);
  }

  buildStage() {
    const { width, height } = this.source;
    this.stage = new Konva.Stage({ container: this.stageHost, width, height });
    const baseLayer = new Konva.Layer({ listening: false });
    baseLayer.add(new Konva.Image({ image: this.source, width, height }));
    this.annotationLayer = new Konva.Layer();
    this.uiLayer = new Konva.Layer();
    this.transformer = new Konva.Transformer({
      rotateEnabled: false,
      ignoreStroke: true,
      flipEnabled: false,
      anchorSize: 10,
      borderStroke: '#0078e6',
      anchorStroke: '#0078e6',
    });
    this.uiLayer.add(this.transformer);
    this.stage.add(baseLayer, this.annotationLayer, this.uiLayer);

    this.stage.on('pointerdown', (event) => this.handlePointerDown(event));
    this.stage.on('pointerup', () => this.editPendingText());
    this.stage.on('dblclick dbltap', (event) => this.handleDoubleClick(event));
    this.stage.on('dragstart', () => {
      this.pendingEdit = null;
    });
    this.stage.on('dragend transformend', () => this.commit());

    this.fit();
    this.resizeObserver = new ResizeObserver(() => this.fit());
    this.resizeObserver.observe(this.viewport);
  }

  fit() {
    const availableWidth = this.viewport.clientWidth || this.host.clientWidth || 800;
    const availableHeight = Math.max(240, Math.round(window.innerHeight * 0.55));
    const scale = Math.min(1, availableWidth / this.source.width, availableHeight / this.source.height);
    if (!Number.isFinite(scale) || scale <= 0 || Math.abs(scale - this.scale) < 0.001 && this.stage.width() > 1) {
      return;
    }
    this.scale = scale;
    this.stage.scale({ x: scale, y: scale });
    this.stage.width(Math.max(1, Math.round(this.source.width * scale)));
    this.stage.height(Math.max(1, Math.round(this.source.height * scale)));
  }

  /**
   * @param {string} tool
   */
  setTool(tool) {
    this.finishText();
    this.tool = tool;
    for (const [id, button] of this.toolButtons) {
      button.classList.toggle('active', id === tool);
      button.setAttribute('aria-pressed', String(id === tool));
    }
    if (tool !== 'select') {
      this.select(null);
    }
    this.annotationLayer?.getChildren().forEach((node) => node.draggable(this.isDraggable(node)));
    this.stageHost.dataset.tool = tool;
  }

  /**
   * @param {string} color
   */
  setColor(color) {
    this.color = color;
    for (const [value, button] of this.colorButtons) {
      button.classList.toggle('active', value === color);
      button.setAttribute('aria-pressed', String(value === color));
    }
    const selected = this.transformer?.nodes()[0];
    if (selected && !selected.hasName('redaction')) {
      this.applyColor(selected, color);
      this.commit();
    }
  }

  /**
   * @param {import('konva').default.Node} node
   * @param {string} color
   */
  applyColor(node, color) {
    if (node instanceof Konva.Label) {
      node.getTag()?.fill(color);
      node.getText()?.fill(getColor(color)?.text ?? '#ffffff');
    } else if (node instanceof Konva.Arrow) {
      node.stroke(color);
      node.fill(color);
    } else if (node instanceof Konva.Line || node instanceof Konva.Rect) {
      node.stroke(color);
    }
  }

  handlePointerDown(event) {
    this.stageHost.focus({ preventScroll: true });
    this.pendingEdit = null;
    if (this.textInput) {
      this.finishText();
      return;
    }
    if (event.target !== this.stage && event.target.getLayer() === this.uiLayer) {
      // Resize handles of the selection
      return;
    }
    const annotation = this.findAnnotation(event.target);
    if (annotation instanceof Konva.Label && this.tool === 'text') {
      // Dragging moves the note, a click edits it
      this.select(annotation);
      this.pendingEdit = annotation;
      return;
    }
    if (annotation) {
      // Every tool selects existing annotations, so they can be changed or deleted
      this.select(annotation);
      return;
    }
    this.select(null);
    if (this.tool === 'select') {
      return;
    }
    const position = this.getPointerPosition();
    if (this.tool === 'text') {
      this.startText(position);
      return;
    }
    const shape = this.createShape(position);
    this.annotationLayer.add(shape);
    this.drawing = { start: position, shape };
    this.trackPointerOutsideStage();
  }

  editPendingText() {
    const callout = this.pendingEdit;
    this.pendingEdit = null;
    if (callout && this.tool === 'text' && callout.getLayer() === this.annotationLayer) {
      this.editText(callout);
    }
  }

  handleDoubleClick(event) {
    const annotation = this.findAnnotation(event.target);
    if (annotation instanceof Konva.Label) {
      this.editText(annotation);
    }
  }

  /**
   * @param {import('konva').default.Node} target
   * @returns {import('konva').default.Node|null}
   */
  findAnnotation(target) {
    if (!target || target === this.stage) {
      return null;
    }
    const annotation = target.findAncestor(`.${ANNOTATION}`, true);
    return annotation && annotation.getLayer() === this.annotationLayer ? annotation : null;
  }

  /**
   * Keeps drawing when the pointer leaves the image and finishes the shape
   * even if the button is released outside of it.
   */
  trackPointerOutsideStage() {
    const move = (event) => {
      this.stage.setPointersPositions(event);
      this.handlePointerMove();
    };
    const up = (event) => {
      window.removeEventListener('pointermove', move, true);
      window.removeEventListener('pointerup', up, true);
      window.removeEventListener('pointercancel', up, true);
      this.stage.setPointersPositions(event);
      this.handlePointerUp();
    };
    window.addEventListener('pointermove', move, true);
    window.addEventListener('pointerup', up, true);
    window.addEventListener('pointercancel', up, true);
  }

  handlePointerMove() {
    if (!this.drawing) {
      return;
    }
    const { start, shape } = this.drawing;
    const position = this.getPointerPosition();
    if (shape instanceof Konva.Arrow) {
      shape.points([start.x, start.y, position.x, position.y]);
    } else if (shape instanceof Konva.Line) {
      const points = shape.points().slice();
      if (appendPoint(points, position.x, position.y, MIN_POINT_DISTANCE / this.scale)) {
        shape.points(points);
      }
    } else {
      shape.setAttrs({
        x: Math.min(start.x, position.x),
        y: Math.min(start.y, position.y),
        width: Math.abs(position.x - start.x),
        height: Math.abs(position.y - start.y),
      });
    }
  }

  handlePointerUp() {
    if (!this.drawing) {
      return;
    }
    const { start, shape } = this.drawing;
    this.drawing = null;
    const position = this.getPointerPosition();
    const minimum = MIN_SHAPE_SIZE / this.scale;
    const tooSmall = shape instanceof Konva.Line && !(shape instanceof Konva.Arrow)
      ? pathLength(shape.points()) < minimum
      : Math.abs(position.x - start.x) < minimum && Math.abs(position.y - start.y) < minimum;
    if (tooSmall) {
      shape.destroy();
      return;
    }
    this.commit();
  }

  /**
   * @param {{x: number, y: number}} position
   */
  createShape(position) {
    if (this.tool === 'arrow') {
      return new Konva.Arrow({
        name: ANNOTATION,
        points: [position.x, position.y, position.x, position.y],
        stroke: this.color,
        fill: this.color,
        strokeWidth: this.strokeWidth,
        pointerLength: this.strokeWidth * 4,
        pointerWidth: this.strokeWidth * 4,
        lineCap: 'round',
        lineJoin: 'round',
        hitStrokeWidth: this.strokeWidth * 4,
      });
    }
    if (this.tool === 'draw') {
      return new Konva.Line({
        name: ANNOTATION,
        points: [position.x, position.y],
        stroke: this.color,
        strokeWidth: this.strokeWidth,
        lineCap: 'round',
        lineJoin: 'round',
        hitStrokeWidth: this.strokeWidth * 4,
      });
    }
    if (this.tool === 'redact') {
      return new Konva.Rect({
        name: `${ANNOTATION} redaction`,
        x: position.x,
        y: position.y,
        width: 0,
        height: 0,
        fill: REDACTION_FILL,
      });
    }
    return new Konva.Rect({
      name: ANNOTATION,
      x: position.x,
      y: position.y,
      width: 0,
      height: 0,
      stroke: this.color,
      strokeWidth: this.strokeWidth,
      cornerRadius: this.strokeWidth,
      hitStrokeWidth: this.strokeWidth * 4,
      fillEnabled: false,
    });
  }

  /**
   * @param {import('konva').default.Label} callout
   */
  editText(callout) {
    this.select(null);
    this.startText({ x: callout.x(), y: callout.y() }, callout);
  }

  /**
   * @param {{x: number, y: number}} position
   * @param {import('konva').default.Label|null} callout An existing text to edit
   */
  startText(position, callout = null) {
    const input = h('input', {
      type: 'text',
      class: 'form-control form-control-sm cr-editor__text-input',
      maxlength: String(MAX_TEXT_LENGTH),
      'aria-label': label(callout ? 'editor.editText' : 'editor.textInput'),
      placeholder: label('editor.textInput'),
      style: `left: ${Math.round(position.x * this.scale)}px; top: ${Math.round(position.y * this.scale)}px`,
      properties: { value: callout?.getText()?.text() ?? '' },
    });
    input.addEventListener('keydown', (event) => {
      event.stopPropagation();
      if (event.key === 'Enter') {
        event.preventDefault();
        this.finishText();
      } else if (event.key === 'Escape') {
        event.preventDefault();
        this.finishText(false);
      }
    });
    // The text is shown in the input while it is edited
    callout?.hide();
    this.textInput = { input, position, callout };
    this.viewport.append(input);
    const focusInput = () => {
      if (this.textInput?.input === input && document.activeElement !== input) {
        input.focus({ preventScroll: true });
        input.select();
      }
    };
    focusInput();
    // A pointer interaction moves the focus back to the stage after this handler
    window.setTimeout(() => {
      if (this.textInput?.input !== input) {
        return;
      }
      focusInput();
      input.addEventListener('blur', () => this.finishText());
    }, 0);
  }

  /**
   * @param {boolean} keep
   */
  finishText(keep = true) {
    if (!this.textInput) {
      return;
    }
    const { input, position, callout } = this.textInput;
    this.textInput = null;
    const text = input.value.trim().slice(0, MAX_TEXT_LENGTH);
    input.remove();
    if (callout) {
      callout.show();
      if (keep && text === '') {
        callout.destroy();
        this.commit();
      } else if (keep && text !== callout.getText().text()) {
        callout.getText().text(text);
        this.commit();
      }
      this.stageHost.focus({ preventScroll: true });
      return;
    }
    if (!keep || text === '') {
      return;
    }
    const color = getColor(this.color) ?? COLORS[0];
    const newCallout = new Konva.Label({ name: ANNOTATION, x: position.x, y: position.y });
    newCallout.draggable(this.isDraggable(newCallout));
    newCallout.add(new Konva.Tag({ fill: color.value, cornerRadius: Math.round(this.fontSize / 4) }));
    newCallout.add(new Konva.Text({
      text,
      fontSize: this.fontSize,
      fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
      fontStyle: 'bold',
      padding: Math.round(this.fontSize / 2.5),
      fill: color.text,
    }));
    this.annotationLayer.add(newCallout);
    this.commit();
    this.stageHost.focus({ preventScroll: true });
  }

  /**
   * @param {import('konva').default.Node|null} node
   */
  select(node) {
    if (!this.transformer) {
      return;
    }
    const previous = this.transformer.nodes()[0];
    if (previous && previous !== node) {
      previous.draggable(this.isDraggable(previous));
    }
    // The selected annotation can be moved with every tool
    node?.draggable(true);
    this.transformer.nodes(node ? [node] : []);
    this.transformer.keepRatio(node instanceof Konva.Label);
    this.transformer.enabledAnchors(node instanceof Konva.Arrow ? [] : RESIZE_ANCHORS);
    this.updateButtons();
  }

  /**
   * Annotations can be dragged with the select tool, notes also with the text
   * tool. The selected annotation can be dragged with every tool.
   *
   * @param {import('konva').default.Node} node
   * @returns {boolean}
   */
  isDraggable(node) {
    return this.tool === 'select' || (this.tool === 'text' && node instanceof Konva.Label);
  }

  deleteSelection() {
    const nodes = this.transformer.nodes();
    if (nodes.length === 0) {
      return;
    }
    nodes.forEach((node) => node.destroy());
    this.select(null);
    this.commit();
  }

  undo() {
    if (this.history.length < 2) {
      return;
    }
    this.future.push(this.history.pop());
    this.restore(this.history[this.history.length - 1]);
  }

  redo() {
    const snapshot = this.future.pop();
    if (snapshot === undefined) {
      return;
    }
    this.history.push(snapshot);
    this.restore(snapshot);
  }

  commit() {
    const snapshot = JSON.stringify(this.annotationLayer.getChildren().map((node) => node.toObject()));
    if (this.history[this.history.length - 1] === snapshot) {
      this.updateButtons();
      return;
    }
    this.history.push(snapshot);
    if (this.history.length > MAX_HISTORY) {
      this.history.shift();
    }
    this.future = [];
    this.updateButtons();
    this.onChange();
  }

  /**
   * @param {string} snapshot
   */
  restore(snapshot) {
    this.select(null);
    this.annotationLayer.destroyChildren();
    for (const data of JSON.parse(snapshot)) {
      const node = Konva.Node.create(data);
      node.draggable(this.isDraggable(node));
      this.annotationLayer.add(node);
    }
    this.updateButtons();
    this.onChange();
  }

  updateButtons() {
    if (!this.undoButton) {
      return;
    }
    this.undoButton.disabled = this.history.length < 2;
    this.redoButton.disabled = this.future.length === 0;
    this.deleteButton.disabled = (this.transformer?.nodes().length ?? 0) === 0;
  }

  /**
   * @param {KeyboardEvent} event
   */
  handleKeydown(event) {
    if (this.textInput) {
      // The note input lost the focus: finish it here instead of closing the dialog with Escape
      if (event.key === 'Enter' || event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.finishText(event.key === 'Enter');
      }
      return;
    }
    const modifier = event.ctrlKey || event.metaKey;
    const key = event.key.toLowerCase();
    if (modifier && key === 'z') {
      event.preventDefault();
      event.stopPropagation();
      event.shiftKey ? this.redo() : this.undo();
      return;
    }
    if (modifier && key === 'y') {
      event.preventDefault();
      event.stopPropagation();
      this.redo();
      return;
    }
    const selected = this.transformer.nodes()[0];
    if ((event.key === 'Delete' || event.key === 'Backspace') && selected) {
      event.preventDefault();
      this.deleteSelection();
      return;
    }
    if (event.key === 'Enter' && selected instanceof Konva.Label) {
      event.preventDefault();
      this.editText(selected);
      return;
    }
    if (event.key === 'Escape' && selected) {
      // Keep the dialog open, only drop the selection
      event.preventDefault();
      event.stopPropagation();
      this.select(null);
      return;
    }
    if (!modifier && !event.altKey) {
      const tool = findToolByKey(key);
      if (tool) {
        event.preventDefault();
        this.setTool(tool.id);
      }
    }
  }

  /**
   * @returns {{x: number, y: number}}
   */
  getPointerPosition() {
    const position = this.stage.getRelativePointerPosition() ?? { x: 0, y: 0 };
    return {
      x: Math.min(Math.max(position.x, 0), this.source.width),
      y: Math.min(Math.max(position.y, 0), this.source.height),
    };
  }
}
