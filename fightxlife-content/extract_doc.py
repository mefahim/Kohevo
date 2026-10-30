#!/usr/bin/env python3
"""Extract readable text from a Google Docs JSON dump (documents.get output).
Usage: extract_doc.py <input.json> <output.txt>
Prints: paragraph count, inline-image count, char count."""
import json
import sys


def para_text(p):
    out = []
    for el in p.get('elements', []):
        tr = el.get('textRun')
        if tr:
            out.append(tr.get('content', ''))
        elif 'inlineObjectElement' in el:
            out.append('[IMAGE]')
    return ''.join(out)


def walk_table(t):
    lines = []
    for row in t.get('tableRows', []):
        cells = []
        for cell in row.get('tableCells', []):
            parts = []
            for el in cell.get('content', []):
                if 'paragraph' in el:
                    parts.append(para_text(el['paragraph']))
                elif 'table' in el:
                    parts.extend(walk_table(el['table']))
            cells.append(' '.join(s for s in parts if s.strip()))
        lines.append(' | '.join(cells))
    return lines


def main():
    data = json.load(open(sys.argv[1], encoding='utf-8'))
    body = data.get('body', {})
    lines = []
    npara = 0
    for el in body.get('content', []):
        if 'paragraph' in el:
            t = para_text(el['paragraph'])
            npara += 1
            if t.strip():
                lines.append(t.rstrip('\n'))
        elif 'table' in el:
            lines.extend(walk_table(el['table']))
    nimg = len(data.get('inlineObjects', {}) or {})
    text = '\n'.join(lines)
    with open(sys.argv[2], 'w', encoding='utf-8') as f:
        f.write('TITLE: ' + data.get('title', '') + '\n')
        f.write(f'PARAGRAPHS: {npara} INLINE-IMAGES: {nimg} CHARS: {len(text)}\n\n')
        f.write(text)
    print(f"{sys.argv[2]}: {npara} paras, {nimg} images, {len(text)} chars")


if __name__ == '__main__':
    main()
