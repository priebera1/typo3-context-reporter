/*
 * Entry point for the vendored Konva build in
 * Resources/Public/JavaScript/contrib/konva.js (npm run build:konva).
 *
 * Only the Konva core and the shapes used by the screenshot editor are
 * bundled. Importing a shape registers it on the Konva namespace, which
 * Konva.Node.create() relies on for undo/redo.
 */
import Konva from 'konva/lib/Core.js';
import 'konva/lib/shapes/Arrow.js';
import 'konva/lib/shapes/Image.js';
import 'konva/lib/shapes/Label.js';
import 'konva/lib/shapes/Line.js';
import 'konva/lib/shapes/Rect.js';
import 'konva/lib/shapes/Text.js';
import 'konva/lib/shapes/Transformer.js';

export default Konva;
