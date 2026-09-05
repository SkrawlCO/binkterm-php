# Upstream dev-image defects (for eventual reporting to kaladron/cpp-image)

Discovered building `kaladron/galactic-bloodshed` against
`ghcr.io/kaladron/cpp-image/dev-env@sha256:79611d08e671ba4a75b01a96331251b7d27b267e42de74e58c89fa0d14ada5c6`
(pulled 2026-09-04/05). Not filed upstream yet -- preserved here as exact,
reproducible facts for whenever that's warranted. Both are dev-image
packaging defects, not bugs in Galactic Bloodshed's own source or CMake
config.

## 1. Unversioned `clang`/`clang++` resolve to a different LLVM major than the installed `libc++-dev`

- `clang --version` / `clang++ --version` → **Clang 21** (Debian's default
  alternatives).
- `libc++-22-dev` / `libc++abi-22-dev` are installed (LLVM 22), but there is
  no matching unversioned `libc++-dev` pointing at 21, and no versioned
  `libc++-21-dev` either -- 22 is the only libc++ available at all.
- Result: `cmake -S . -B build` with the project's `CMAKE_CXX_FLAGS
  "-stdlib=libc++"` and unversioned `clang++` compiles against LLVM 21's
  driver defaults while linking a v22 static libc++, producing floods of
  `undefined reference` errors on ABI-tagged symbols (e.g. `std::__1::
  basic_string<...>::append[abi:nqe220101](...)`) -- classic
  library/compiler major mismatch, not a real missing symbol.
- Fix used downstream: always invoke `clang-22`/`clang++-22` explicitly;
  never the unversioned names. See `toolchain-clang22-libcxx22.cmake`.

## 2. CMake's "import std" module support guesses the wrong libc++ module path

- With `CMAKE_CXX_MODULE_STD ON` (which GB's `CMakeLists.txt` sets, required
  for `import std;`), CMake 4.2's built-in std-module detection computes the
  module search directory relative to the *compiler's own directory* and
  looks for it at `/lib/share/libc++/v1/std.cppm`.
- That path does not exist on this image at all (real location:
  `/usr/lib/llvm-22/share/libc++/v1/std.cppm`), regardless of which
  clang binary (21 or 22, versioned or not) is selected as
  `CMAKE_CXX_COMPILER` -- the guess itself is wrong on this image's layout,
  before the version-mismatch in #1 even comes into play.
- Symptom without a fix: `CMake Error ... Cannot find source file:
  /lib/share/libc++/v1/std.cppm` during configure, or (if a raw file symlink
  is placed at just that one path without its sibling `std/*.inc` files)
  `fatal error: 'std/algorithm.inc' file not found` during the dependency
  scan.
- Fix used downstream: symlink the whole directory --
  `/lib/share/libc++/v1 -> /usr/lib/llvm-22/share/libc++/v1` -- so relative
  includes inside `std.cppm` still resolve. See `Dockerfile.builder`.

## Not a defect, but worth noting

- `lld-22` (providing `/usr/bin/ld.lld-22`) is **already installed** in the
  base image. There is no unversioned `ld.lld`/`lld` on the image as
  shipped. GB's own `CMakeLists.txt` does `find_program(NAMES ld.lld lld)`
  and silently falls back to GNU `ld.bfd` when neither is found by that
  generic name -- and `ld.bfd` cannot reliably link the interdependent
  static-libc++ + C++20-module-archive combination this project produces
  (undefined references to basic_string dtors, `__cxa_*`, RTTI, etc., even
  though every archive is present on the link line). This isn't an upstream
  *image* bug so much as a CMakeLists.txt detection gap that only surfaces
  because the image ships lld exclusively under its versioned name. Worth
  mentioning alongside the two real defects above if this ever gets filed,
  since the practical effect (a working build) requires knowing to bypass
  that probe with an explicit `-fuse-ld=/usr/bin/ld.lld-22`, which is what
  `toolchain-clang22-libcxx22.cmake` does.
