import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PNG } from 'pngjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(__dirname, '../public/logo.png');
const outDir = join(__dirname, '../public/icons');

const source = PNG.sync.read(readFileSync(sourcePath));

function findContentBounds(png) {
  const { width, height, data } = png;
  let minX = width;
  let minY = height;
  let maxX = -1;
  let maxY = -1;

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = (y * width + x) * 4;
      const alpha = data[i + 3];
      const luminance = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
      if (alpha > 16 && luminance > 24) {
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        if (y < minY) minY = y;
        if (y > maxY) maxY = y;
      }
    }
  }

  return { minX, minY, maxX, maxY };
}

function cropAndPad(png, bounds, padFraction) {
  const contentWidth = bounds.maxX - bounds.minX + 1;
  const contentHeight = bounds.maxY - bounds.minY + 1;
  const pad = Math.round(Math.max(contentWidth, contentHeight) * padFraction);
  const side = Math.max(contentWidth, contentHeight) + pad * 2;

  const out = new PNG({ width: side, height: side });

  // Solid black background (matches the logo identity).
  for (let i = 0; i < side * side * 4; i += 4) {
    out.data[i] = 0;
    out.data[i + 1] = 0;
    out.data[i + 2] = 0;
    out.data[i + 3] = 255;
  }

  const offsetX = Math.round((side - contentWidth) / 2) - bounds.minX;
  const offsetY = Math.round((side - contentHeight) / 2) - bounds.minY;

  for (let y = bounds.minY; y <= bounds.maxY; y++) {
    for (let x = bounds.minX; x <= bounds.maxX; x++) {
      const srcIndex = (y * png.width + x) * 4;
      const dstIndex = ((y + offsetY) * side + (x + offsetX)) * 4;
      out.data[dstIndex] = png.data[srcIndex];
      out.data[dstIndex + 1] = png.data[srcIndex + 1];
      out.data[dstIndex + 2] = png.data[srcIndex + 2];
      out.data[dstIndex + 3] = png.data[srcIndex + 3];
    }
  }

  return out;
}

function resize(png, target) {
  const out = new PNG({ width: target, height: target });
  const srcWidth = png.width;
  const srcHeight = png.height;

  for (let y = 0; y < target; y++) {
    const sy0 = Math.floor((y * srcHeight) / target);
    const sy1 = Math.max(sy0 + 1, Math.floor(((y + 1) * srcHeight) / target));
    for (let x = 0; x < target; x++) {
      const sx0 = Math.floor((x * srcWidth) / target);
      const sx1 = Math.max(sx0 + 1, Math.floor(((x + 1) * srcWidth) / target));

      let r = 0;
      let g = 0;
      let b = 0;
      let a = 0;
      let count = 0;

      for (let sy = sy0; sy < sy1; sy++) {
        for (let sx = sx0; sx < sx1; sx++) {
          const srcIndex = (sy * srcWidth + sx) * 4;
          r += png.data[srcIndex];
          g += png.data[srcIndex + 1];
          b += png.data[srcIndex + 2];
          a += png.data[srcIndex + 3];
          count++;
        }
      }

      const dstIndex = (y * target + x) * 4;
      out.data[dstIndex] = r / count;
      out.data[dstIndex + 1] = g / count;
      out.data[dstIndex + 2] = b / count;
      out.data[dstIndex + 3] = a / count;
    }
  }

  return out;
}

const bounds = findContentBounds(source);
const contentWidth = bounds.maxX - bounds.minX + 1;
const contentHeight = bounds.maxY - bounds.minY + 1;
console.log(`content bounds: ${contentWidth}x${contentHeight} (offset ${bounds.minX},${bounds.minY})`);

mkdirSync(outDir, { recursive: true });

const targets = [
  { name: 'favicon-16.png', size: 16, pad: 0.1 },
  { name: 'favicon-32.png', size: 32, pad: 0.1 },
  { name: 'apple-touch-icon.png', size: 180, pad: 0.1 },
  { name: 'icon-192.png', size: 192, pad: 0.1 },
  { name: 'icon-512.png', size: 512, pad: 0.1 },
  // Maskable icons need extra safe zone: content must sit inside a centered 80% circle.
  { name: 'icon-512-maskable.png', size: 512, pad: 0.24 },
];

for (const target of targets) {
  const padded = cropAndPad(source, bounds, target.pad);
  const resized = resize(padded, target.size);
  const outPath = join(outDir, target.name);
  writeFileSync(outPath, PNG.sync.write(resized));
  console.log(`wrote ${target.name} (${target.size}x${target.size})`);
}
