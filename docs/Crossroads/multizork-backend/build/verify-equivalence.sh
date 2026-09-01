#!/usr/bin/env bash
#
# The build embeds __DATE__/__TIME__, so two builds of the same source never
# share a SHA-256.  Reproducibility is therefore proven by *instruction*
# equivalence instead: this script disassembles two binaries and compares them
# per function, with addresses / symbol offsets / NOP padding normalised out.
#
#   ./verify-equivalence.sh <binary-A> <binary-B>
#
# Expected results for this change:
#   verify-equivalence.sh multizorkd.DEPLOYED  multizorkd.p1
#       -> 0 differing instructions, 0 functions changed
#   verify-equivalence.sh multizorkd.p1        multizorkd.p2
#       -> exactly ONE function changed: `main` (process_connection_command is
#          static and -O2-inlined into it) -- the conn->inputfn compare + the
#          extra loginfo("... <redacted>") call, plus register-allocation
#          ripple inside that one function.  Nothing else moves.
#
set -euo pipefail
A="${1:?usage: verify-equivalence.sh <binA> <binB>}"
B="${2:?usage: verify-equivalence.sh <binA> <binB>}"

# whole-.text normalised diff -----------------------------------------------
norm() {
  objdump -d --no-show-raw-insn "$1" \
  | sed -E 's/^\s+[0-9a-f]+:\s*//;
            s/\b[0-9a-f]{4,}\b//g;
            s/0x[0-9a-f]+/H/g;
            s/<[A-Za-z_][A-Za-z0-9_.]*(\+H)?>/<S>/g;
            s/#.*//; s/\s+/ /g; s/\s+$//' \
  | grep -vE '^$|file format|Disassembly|^<S>:'
}
n=$(diff <(norm "$A") <(norm "$B") | grep -cE '^[<>]' || true)
echo "normalised .text differences: $n line(s)"

# per-function diff --------------------------------------------------------
dump_funcs() {  # -> $tmp/<funcname>  : normalised body of each function
  local bin="$1" out="$2"
  mkdir -p "$out"
  objdump -d --no-show-raw-insn "$bin" | awk -v d="$out" '
    /^[0-9a-f]+ <.*>:$/ { f=$2; gsub(/[<>:]/,"",f); next }
    f && NF {
      line=$0
      sub(/^[[:space:]]+[0-9a-f]+:[[:space:]]*/,"",line)
      gsub(/0x[0-9a-f]+/,"H",line)
      gsub(/[0-9a-f]{4,} </,"< ",line)
      print line >> (d"/"f)
    }
    /^$/ { f="" }'
}
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
dump_funcs "$A" "$tmp/a"
dump_funcs "$B" "$tmp/b"

changed=()
for f in $(ls "$tmp/a" "$tmp/b" | grep -v ':' | sort -u); do
  if ! diff -q "$tmp/a/$f" "$tmp/b/$f" >/dev/null 2>&1; then
    changed+=("$f")
  fi
done
echo "functions with a normalised difference: ${#changed[@]}"
for f in "${changed[@]}"; do echo "  - $f"; done
exit 0
