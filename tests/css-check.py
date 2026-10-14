#!/usr/bin/env python3
"""Static check of assets/admin.css: every control of the panel has an explicit text colour for each of its states
(hover / focus / active / disabled), the text/background pair has contrast >= 4.5 (WCAG AA), and nothing goes black.
Resolves var(--x) from :root. Run: python3 tests/css-check.py   (exit 1 on any failure).
The browser-side counterpart (real computed styles, with WordPress' own :hover rules injected) is tests/css-states.js."""
import re
import sys
from pathlib import Path

css = Path(__file__).resolve().parent.parent.joinpath('assets/admin.css').read_text(encoding='utf-8')
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
rules = []  # (selectors, {prop: value}) in file order
for m in re.finditer(r'([^{}]+)\{([^{}]*)\}', css):
    sels = [x.strip() for x in m.group(1).split(',') if x.strip()]
    decls = {}
    for d in m.group(2).split(';'):
        if ':' in d:
            k, v = d.split(':', 1)
            decls[k.strip()] = v.replace('!important', '').strip()
    rules.append((sels, decls))

root = {}
for sels, decls in rules:
    if ':root' in sels:
        root = {k: v for k, v in decls.items() if k.startswith('--')}


def resolve(v):
    m = re.fullmatch(r'var\((--[\w-]+)\)', v.strip())
    return root.get(m.group(1), v) if m else v


def rgb(v):
    v = resolve(v).strip().lower()
    if v in ('transparent', 'none'):
        return None
    m = re.fullmatch(r'#([0-9a-f]{3}|[0-9a-f]{6})', v)
    if not m:
        raise ValueError('unsupported colour ' + v)
    h = m.group(1)
    h = ''.join(c * 2 for c in h) if len(h) == 3 else h
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def lum(c):
    def f(x):
        x /= 255
        return x / 12.92 if x <= 0.03928 else ((x + 0.055) / 1.055) ** 2.4
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2])


def contrast(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)


SURFACES = [rgb('var(--bg)'), rgb('var(--raised)'), rgb('var(--hover)')]  # what a transparent control can sit on
INPUT = ".mlt input:not([type=checkbox]):not([type=radio]):not([type=file])"
CONTROLS = {
    'select':        (".mlt select",                 ['', ':hover', ':focus', ':active', ':disabled']),
    'text input':    (INPUT,                         [':hover', ':focus', ':disabled']),
    'textarea':      (".mlt textarea",               [':hover', ':focus', ':disabled']),
    'primary button': (".mlt .mlt-btn",              ['', ':hover', ':focus', ':active', ':disabled']),
    'ghost button':  (".mlt .mlt-btn--ghost",        ['', ':hover', ':focus', ':active', ':disabled']),
    'link button':   (".mlt .mlt-link-btn",          ['', ':hover', ':focus', ':active', ':disabled']),
    'danger link button': (".mlt .mlt-link-btn--danger", [':hover', ':focus', ':active']),
    'mine/all switch': (".mlt .mlt-seg a",           ['', ':hover', ':focus', ':active']),
    'switch (current)': (".mlt .mlt-seg a.is-on",    ['', ':hover', ':focus', ':active']),
    'tri-state button': (".mlt .mlt-tri button",     ['', ':not([disabled]):hover', ':not([disabled]):focus', ':not([disabled]):active']),
    'task code':     (".mlt .mlt-code",              ['', ':hover', ':focus', ':active']),
    'close x':       (".mlt .mlt-x",                 ['', ':hover', ':focus', ':active']),
    'help ?':        (".mlt .mlt-tip",               ['', ':hover', ':focus', ':active']),
    'link':          (".mlt a",                      ['', ':hover', ':focus', ':active']),
}

fail = 0
print(f"{'control':22} {'state':26} {'text':8} {'background':12} contrast")
for name, (base, states) in CONTROLS.items():
    for st in states:
        sel = base + st
        found = [d for sels, d in rules if sel in sels]
        # a state rule may be shared by several selectors; the last matching rule wins for each property
        color = next((d['color'] for d in reversed(found) if 'color' in d), None)
        bg = next((d.get('background-color', d.get('background')) for d in reversed(found) if ('background-color' in d or 'background' in d)), None)
        if color is None:
            print(f"{name:22} {st or 'normal':26} MISSING: no explicit text colour"); fail += 1
            continue
        text = rgb(color)
        if bg is not None and rgb(bg) is not None:
            ratios = [contrast(text, rgb(bg))]
            shown = resolve(bg)
        else:
            ratios = [contrast(text, s) for s in SURFACES]  # transparent: it sits on the page / card / hover surface
            shown = 'transparent'
        low = min(ratios)
        ok = low >= 4.5 and text != (0, 0, 0)
        print(f"{name:22} {st or 'normal':26} {resolve(color):8} {shown:12} {low:5.1f} {'ok' if ok else 'LOW'}")
        fail += 0 if ok else 1
print('\nall states readable' if not fail else f'\n{fail} problem(s)')
sys.exit(1 if fail else 0)
