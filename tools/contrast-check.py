#!/usr/bin/env python3
"""WCAG contrast check for the VATSSA ControlCentre theme.

Parses `_custom.scss` for its `--var: #hex;` declarations per theme block,
fills in anything it does not override from upstream's `_light.scss` /
`_dark.scss` defaults, then checks every pair that has to hold.

Usage:  python contrast-check.py [path/to/_custom.scss]
Exit 1 on any failure. Re-run after ANY colour change.
"""
import re
import sys
import pathlib

path = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "_custom.scss")
src = re.sub(r"//[^\n]*", "", path.read_text(encoding="utf-8"))

# Upstream v7.0.0 defaults, for tokens the override does not set.
UPSTREAM = {
    "light": {
        "--color-primary": "#1a475f", "--color-on-primary": "#ffffff",
        "--color-primary-emphasis": "#1a475f", "--color-primary-outline": "#1a475f",
        "--color-primary-text": "#1a475f", "--color-body": "#011328",
        "--color-text-primary": "#3a3b45", "--color-text-secondary": "#858796",
        "--color-text-muted": "#858796", "--bg-body": "#f8f9fc", "--bg-card": "#ffffff",
        "--bg-card-header": "#f8f9fc", "--bg-sidebar": "#1a475f", "--border-color": "#e3e6f0",
        "--link-color": "#1a475f", "--link-hover-color": "#011328",
        "--color-sidebar-text": "#ffffff", "--color-danger": "#b63f3f",
        "--color-success": "#41826e", "--color-warning": "#ff9800", "--color-info": "#17a2b8",
    },
    "dark": {
        "--color-primary": "#2c6285", "--color-on-primary": "#ececec",
        "--color-primary-emphasis": "#3a7ca5", "--color-primary-outline": "#4288b0",
        "--color-primary-text": "#56a3cc", "--color-body": "#e8eaed",
        "--color-text-primary": "#f5f5f5", "--color-text-secondary": "#b0b0b0",
        "--color-text-muted": "#aaaaaa", "--bg-body": "#1b2128", "--bg-card": "#2a2a2a",
        "--bg-card-header": "#333333", "--bg-sidebar": "#0f1419", "--border-color": "#3d3d3d",
        "--link-color": "#4a9cc5", "--link-hover-color": "#6bb6d9",
        "--color-sidebar-text": "#ffffff", "--color-danger": "#e57373",
        "--color-success": "#40896e", "--color-warning": "#ffb347", "--color-info": "#4a9cc5",
    },
}


def block(selector):
    i = src.index(selector)
    i = src.index("{", i)
    depth, j = 0, i
    while True:
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[i + 1:j]
        j += 1


def theme(name, selector):
    vals = dict(UPSTREAM[name])
    for k, v in re.findall(r"(--[a-z0-9\-]+)\s*:\s*(#[0-9a-fA-F]{3,6})\s*;", block(selector)):
        vals[k] = v.lower()
    return vals


def srgb(c):
    c /= 255
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def lum(h):
    h = h.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
    return 0.2126 * srgb(r) + 0.7152 * srgb(g) + 0.0722 * srgb(b)


def cr(a, b):
    la, lb = lum(a), lum(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)


# fg, bg, minimum. 4.5 = AA body text. 3.0 = AA non-text UI (fills, focus rings).
# 1.2 / 1.05 = surfaces must be visibly distinct, not a contrast standard.
PAIRS = [
    ("body text on page",        "--color-text-primary", "--bg-body",       4.5),
    ("body text on card",        "--color-text-primary", "--bg-card",       4.5),
    ("body text on card header", "--color-text-primary", "--bg-card-header", 4.5),
    ("muted text on card",       "--color-text-muted",   "--bg-card",       4.5),
    ("secondary text on page",   "--color-text-secondary", "--bg-body",     4.5),
    ("link on card",             "--link-color",         "--bg-card",       4.5),
    ("link hover on card",       "--link-hover-color",   "--bg-card",       4.5),
    ("link on page",             "--link-color",         "--bg-body",       4.5),
    ("heading text on card",     "--color-primary-text", "--bg-card",       4.5),
    ("outline btn text on page", "--color-primary-outline", "--bg-body",    4.5),
    ("text on primary fill",     "--color-on-primary",   "--color-primary", 4.5),
    ("text on emphasis fill",    "--color-on-primary", "--color-primary-emphasis", 4.5),
    ("primary fill vs page",     "--color-primary",      "--bg-body",       3.0),
    ("emphasis fill vs page",    "--color-primary-emphasis", "--bg-body",   3.0),
    ("sidebar text on sidebar",  "--color-sidebar-text", "--bg-sidebar",    4.5),
    ("danger text on card",      "--color-danger",       "--bg-card",       4.5),
    ("success text on card",     "--color-success",      "--bg-card",       4.5),
    # Warning and info are alert/badge FILLS, not body text, so the bar is 3:1.
    ("warning fill on card",     "--color-warning",      "--bg-card",       3.0),
    ("info fill on card",        "--color-info",         "--bg-card",       3.0),
    ("card vs page",             "--bg-card",            "--bg-body",       1.2),
    ("card header vs card",      "--bg-card-header",     "--bg-card",       1.05),
    ("border vs card",           "--border-color",       "--bg-card",       1.1),
]

fails = 0
for name, selector in (("light", ":root,"), ("dark", '[data-theme="dark"]')):
    vals = theme(name, selector)
    print(f"\n--- {name} ---")
    for label, fg, bg, need in PAIRS:
        r = cr(vals[fg], vals[bg])
        ok = r >= need
        fails += not ok
        print(f"  {label:26} {vals[fg]} on {vals[bg]}  {r:5.2f}  "
              f"{'OK ' if ok else 'FAIL'} (>= {need})")

print(f"\n{fails} failure(s)")
sys.exit(1 if fails else 0)
