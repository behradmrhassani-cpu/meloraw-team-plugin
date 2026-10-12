#!/usr/bin/env python3
"""Reads an admin page's HTML on stdin and prints the url-encoded body of the first <form> whose hidden
inputs match every NAME=VALUE given on the command line (e.g. action=mlt_banner do=hide). Extra fields can be
added or overridden with +NAME=VALUE. Used by tests/ui-check.sh to submit the panel's own forms, nonce included."""
import sys
from html.parser import HTMLParser
from urllib.parse import urlencode


class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms, self.cur = [], None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = []
        elif self.cur is not None and tag in ('input', 'select', 'textarea') and a.get('name'):
            self.cur.append((a['name'], a.get('value', ''), a.get('type', tag)))

    def handle_endtag(self, tag):
        if tag == 'form' and self.cur is not None:
            self.forms.append(self.cur)
            self.cur = None


want = [x.split('=', 1) for x in sys.argv[1:] if not x.startswith('+')]
extra = [x[1:].split('=', 1) for x in sys.argv[1:] if x.startswith('+')]
p = Forms()
p.feed(sys.stdin.read())
for form in p.forms:
    hidden = {n: v for n, v, t in form if t == 'hidden'}
    if all(hidden.get(k) == v for k, v in want):
        body = [(n, v) for n, v, t in form if t == 'hidden' and n not in dict(extra)] + [tuple(e) for e in extra]
        print(urlencode(body))
        sys.exit(0)
sys.exit(1)
