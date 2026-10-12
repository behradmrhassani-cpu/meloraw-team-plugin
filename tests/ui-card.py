#!/usr/bin/env python3
"""Reads an admin page's HTML on stdin. Usage:
  ui-card.py ids              -> the task ids of every <article id="mlt-t-N"> (one per line, in order)
  ui-card.py card N           -> the markup of article mlt-t-N (raw, for grep)
  ui-card.py field N NAME     -> the text inside the card's element with class NAME (e.g. mlt-handoff textarea use 'handoff')
Used by tests/ui-check.sh."""
import re
import sys
from html import unescape

html = sys.stdin.read()
mode = sys.argv[1]
if mode == 'ids':
    print('\n'.join(re.findall(r'<article id="mlt-t-(\d+)"', html)))
    sys.exit(0)
m = re.search(r'<article id="mlt-t-%s".*?</article>' % re.escape(sys.argv[2]), html, re.S)
if not m:
    sys.exit(1)
card = m.group(0)
if mode == 'card':
    print(card)
elif mode == 'handoff':
    t = re.search(r'<textarea id="mlt-h-\d+"[^>]*>(.*?)</textarea>', card, re.S)
    print(unescape(t.group(1)) if t else '')
