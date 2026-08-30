#!/usr/bin/env python3
"""Expand a CC v7 config/roles.php and check the VATSSA invariants.

Mirrors app/Services/PermissionMatrix.php exactly:
  '*'  -> exactly one segment  ([^.]+)
  '**' -> one or more segments (.+)
  '!'  -> deny; deny always wins regardless of order

Usage:  python expand.py [path/to/roles.php]   (default: ./roles.php)
Re-run after ANY edit to the matrix. Writes matrix-table.md beside THIS script,
not beside the input, so it never lands in config/.
"""
import re
import sys
import pathlib

path = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "roles.php")
src = path.read_text(encoding="utf-8")

# Strip // comments so commented-out entries never count as real.
src = re.sub(r"//[^\n]*", "", src)


def block(name):
    """Return the text of the top-level `'name' => [ ... ]` array."""
    i = src.index(f"'{name}' =>")
    i = src.index("[", i)
    depth, j = 0, i
    while True:
        if src[j] == "[":
            depth += 1
        elif src[j] == "]":
            depth -= 1
            if depth == 0:
                return src[i + 1:j]
        j += 1


PERMISSIONS = re.findall(r"'([a-z0-9.\-*!]+)'", block("permissions"))

roles_block = block("roles")
ROLES = re.findall(r"'([a-z0-9\-]+)' =>\s*\[", roles_block)

matrix_block = block("matrix")
MATRIX = {}
for m in re.finditer(r"'([a-z0-9\-]+)' =>\s*\[(.*?)\]", matrix_block, re.S):
    MATRIX[m.group(1)] = re.findall(r"'([^']+)'", m.group(2))


def to_regex(pattern):
    parts = [re.escape(p) if p not in ("*", "**") else (r"[^.]+" if p == "*" else r".+")
             for p in pattern.split(".")]
    return re.compile("^" + r"\.".join(parts) + "$")


def resolve(patterns):
    allow, deny = set(), set()
    for pat in patterns:
        target, negate = (pat[1:], True) if pat.startswith("!") else (pat, False)
        rx = to_regex(target)
        hit = {p for p in PERMISSIONS if rx.match(p)}
        (deny if negate else allow).update(hit)
    return allow - deny


held = {r: resolve(MATRIX.get(r, [])) for r in ROLES}

errors = []

# 1. Every role in the catalogue has a matrix entry, and vice versa.
for r in ROLES:
    if r not in MATRIX:
        errors.append(f"role '{r}' is in the catalogue but has no matrix entry")
for r in MATRIX:
    if r not in ROLES:
        errors.append(f"matrix entry '{r}' has no role in the catalogue")

# 2. Every pattern matches at least one catalogued permission.
for r, pats in MATRIX.items():
    for pat in pats:
        target = pat[1:] if pat.startswith("!") else pat
        if not any(to_regex(target).match(p) for p in PERMISSIONS):
            errors.append(f"{r}: pattern '{pat}' matches no catalogued permission")

# 3. Every roles.<key>.manage entry names a real role, and every grantable
#    role has one.
#
#    Grantability is read from the role's own `grantable` key rather than by
#    naming admin here. Three roles are ungrantable now -- admin, which is
#    CLI-only, and the two retired ones kept so RoleAssignment's validator
#    recognises them -- and a list of exceptions maintained in two places is
#    the thing that goes stale.
def _ungrantable(text):
    """Roles whose block carries `'grantable' => false`.

    Walks backwards from each flag to the nearest `'key' => [` rather than
    matching forwards from one. A forward match starts at the OUTER array and
    happily captures 'roles' itself, because `[^]]*` will cross every nested
    opening bracket on the way to the flag.
    """
    found = set()
    for hit in re.finditer(r"'grantable'\s*=>\s*false", text):
        before = text[:hit.start()]
        keys = re.findall(r"'([a-z-]+)'\s*=>\s*\[", before)
        if keys:
            found.add(keys[-1])
    return found


UNGRANTABLE = _ungrantable(src)
grantable = [r for r in ROLES if r not in UNGRANTABLE]
grant_perms = {p for p in PERMISSIONS if p.startswith("roles.")}
for p in grant_perms:
    key = p[len("roles."):-len(".manage")]
    if key not in ROLES:
        errors.append(f"grant permission '{p}' names no role in the catalogue")
for r in grantable:
    if f"roles.{r}.manage" not in grant_perms:
        errors.append(f"role '{r}' is grantable but has no roles.{r}.manage permission")
if "roles.admin.manage" in grant_perms:
    errors.append("roles.admin.manage exists — admin is CLI-only and must not be grantable")

# 4. VATSSA invariant: ATM is a superset of Pipeline Coordinator.
if "atc-training-manager" in held and "pipeline-coordinator" in held:
    missing = held["pipeline-coordinator"] - held["atc-training-manager"]
    if missing:
        errors.append("ATM is NOT a superset of Pipeline Coordinator; missing "
                      + ", ".join(sorted(missing)))

# 5. Orphans: catalogued permissions nobody holds.
orphans = sorted(p for p in PERMISSIONS if not any(p in held[r] for r in ROLES))

lines = [f"# Expanded matrix — {path.name}", "",
         f"{len(PERMISSIONS)} permissions x {len(ROLES)} roles.", "",
         "| Permission | " + " | ".join(ROLES) + " |",
         "|---|" + "---|" * len(ROLES)]
for p in PERMISSIONS:
    lines.append(f"| `{p}` | " + " | ".join("O" if p in held[r] else "" for r in ROLES) + " |")
lines += ["", "**Totals:** " + " · ".join(f"{r} **{len(held[r])}**" for r in ROLES)]
if orphans:
    lines += ["", "**Held by nobody:** " + ", ".join(f"`{p}`" for p in orphans)]
# newline="\n" so the file is LF on Windows too, or it shows as permanently
# modified in a repo that stores it as LF.
(pathlib.Path(__file__).resolve().parent / "matrix-table.md").write_text(
    "\n".join(lines) + "\n", encoding="utf-8", newline="\n")

print(f"{len(PERMISSIONS)} permissions, {len(ROLES)} roles")
for r in ROLES:
    print(f"  {r:24} {len(held[r])}")
if orphans:
    print("held by nobody: " + ", ".join(orphans))
if errors:
    print("\nFAIL")
    for e in errors:
        print("  - " + e)
    sys.exit(1)
print("\nOK - all checks pass. Wrote matrix-table.md")
