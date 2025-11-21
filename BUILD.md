# Build System

## Quick Start

### Development Build (Fast - ~2 seconds)

```bash
pnpm run build:dev
```

- No minification
- Includes sourcemaps
- Output: 1.3MB unminified bundle
- Use this during active development

### Production Build (Optimized - ~30 seconds)

```bash
pnpm run build
```

- Full minification with Terser
- No sourcemaps
- Output: 696KB minified bundle
- Use this for releases and deployments

## Build Commands

- `pnpm run build:js` - Build JS only (production)
- `pnpm run build:js:dev` - Build JS only (development)
- `pnpm run build:css` - Build CSS only
- `pnpm run build` - Full production build
- `pnpm run build:dev` - Full development build
- `pnpm run clean` - Remove assets folder
- `pnpm run validate` - Validate source files exist

## Source → Output

**JavaScript:**

- Source: `src/js/sparxstar-bootstrap.js` (entry point)
- Bundles: All `src/js/*.js` files + vendor dependencies
- Output: `assets/js/sparxstar-user-environment-check-app.bundle.min.js`

**CSS:**

- Source: `src/css/sparxstar-user-environment-check.css`
- Output: `assets/css/sparxstar-user-environment-check.min.css`

## Vendor Dependencies

The following libraries are bundled into the JavaScript output:

- `@fingerprintjs/fingerprintjs@^5.0.1`
- `device-detector-js@^3.0.3`

These are automatically pulled from `node_modules` during the build process.

## Why is the production build slow?

The Terser minification step takes ~28 seconds to compress the 1.3MB bundle down to 696KB. This is normal for large bundles. Use `pnpm run build:dev` during development for much faster builds.
