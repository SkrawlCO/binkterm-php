# Sixel Support

BinktermPHP renders DEC Sixel graphics embedded in messages, uploaded as files, and streamed live by a NativeDoor game running in a web terminal session.

## Overview

Sixel is a bitmap graphics format developed by Digital Equipment Corporation (DEC) for VT240/VT340 terminals. It encodes raster images as streams of six-pixel-tall columns using printable ASCII characters, and is still widely supported by modern terminals (xterm, iTerm2, mlterm, SyncTerm, etc.). Static content (messages, file previews) is decoded and rendered entirely in the browser using BinktermPHP's own HTML5 `<canvas>` decoder; a live NativeDoor terminal session instead renders sixel through xterm.js's own image addon (see below) — the two are separate renderers for two different contexts.

## Where Sixel Renders

### In Messages (Echomail & Netmail)

When viewing an echomail or netmail message, the message reader scans the body for embedded sixel data (sequences beginning with `ESC P` / `ESC P ... q`). If found, the sixel segments are rendered to canvas inline with any surrounding plain text.

The `renderSixelChunks()` function handles mixed content — a message may contain both plain text sections and one or more sixel image blocks, all rendered in order.

### In File Areas

Files with `.six` or `.sixel` extensions are automatically previewed as sixel images in the file browser. The preview renders the file content to a canvas element in the file detail panel.

### In a Live Web NativeDoor Session

A NativeDoor game played from the web (`/games/nativedoors/{id}`, the DOS Door Player at `public_html/webdoors/dosdoors/index.php`) runs in a live xterm.js terminal fed byte-for-byte from the door's PTY over WebSocket. xterm.js core has no sixel/image support of its own — DCS sixel sequences arriving in a live stream would otherwise be silently discarded by its parser (text and ordinary ANSI/CP437 output are unaffected either way, since those don't depend on this addon). The player loads the official `@xterm/addon-image` companion (`public_html/webdoors/terminal/assets/xterm-addon-image.js`, vendored the same way as `xterm-addon-fit.js`) via the normal `term.loadAddon(...)` API, which decodes DCS sixel (and iTerm inline-image) sequences to an overlaid `<canvas>` as they stream in. This is what makes SyncDOOM's forced `-sixel 1` graphical output visible when played from the web, matching what a real sixel-capable terminal (e.g. SyncTerm over Telnet) already showed. It is a generic capability — any NativeDoor emitting sixel over the web terminal benefits, not just SyncDOOM.

## Supported Features

The sixel decoder in `public_html/js/sixel.js` supports:

- **256-color palette** — default VT340 palette for registers 0–15, remainder default to black until defined by the stream
- **HLS and RGB color definition** (`#n;2;r;g;b` and `#n;1;h;l;s`)
- **Repeat introducer** (`!count char`) for run-length encoded rows
- **Carriage return** (`$`) and next-row (`-`) control characters
- **Raster attributes** (`"Pan;Pad;Ph;Pv`) for aspect ratio and canvas size hints
- **Transparent background** — background pixels default to transparent

## Default Palette

The first 16 color registers use the VT340 palette:

| Register | Color |
|----------|-------|
| 0 | Black |
| 1 | Blue |
| 2 | Red |
| 3 | Green |
| 4 | Magenta |
| 5 | Cyan |
| 6 | Yellow |
| 7 | Gray 50% |
| 8 | Gray 33% |
| 9 | Light Blue |
| 10 | Light Red |
| 11 | Light Green |
| 12 | Light Magenta |
| 13 | Light Cyan |
| 14 | Light Yellow |
| 15 | White |

Registers 16–255 default to opaque black until redefined by the stream.

## Public JS API

The sixel renderer exposes these functions globally:

```javascript
// Returns true if the string contains a sixel DCS sequence
looksLikeSixel(text)

// Decodes and renders sixel data, returns an HTMLCanvasElement or null
renderSixelToCanvas(sixelData)

// Renders mixed text+sixel content into a container element
// textChunks are rendered using the provided renderTextFn callback
renderSixelChunks(container, rawText, renderTextFn)

// Renders a sixel file preview into a jQuery container
renderSixelFilePreview($container, text)
```

## Notes

- Sixel rendering is entirely client-side — no server processing is required.
- Very large sixel images may take a moment to decode depending on resolution and color depth.
- The canvas element scales with the container; actual pixel dimensions are determined by the sixel stream's raster attributes or by the decoded content size.
