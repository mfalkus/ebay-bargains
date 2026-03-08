/**
 * Copy front-end vendor assets from node_modules to public/vendor.
 * Run: npm install && npm run build
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const nodeModules = path.join(root, 'node_modules');
const vendorDir = path.join(root, 'public', 'vendor');

const files = [
  { from: 'moment/min/moment.min.js', to: 'moment.min.js' },
  { from: 'tom-select/dist/css/tom-select.css', to: 'tom-select.css' },
  { from: 'tom-select/dist/js/tom-select.complete.min.js', to: 'tom-select.complete.min.js' },
];

if (!fs.existsSync(nodeModules)) {
  console.error('Run npm install first.');
  process.exit(1);
}

if (!fs.existsSync(vendorDir)) {
  fs.mkdirSync(vendorDir, { recursive: true });
}

let copied = 0;
for (const { from, to } of files) {
  const src = path.join(nodeModules, from);
  const dest = path.join(vendorDir, to);
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, dest);
    console.log('Copied', to);
    copied++;
  } else {
    console.warn('Missing:', from);
  }
}

console.log('Done. %d file(s) in public/vendor/', copied);
