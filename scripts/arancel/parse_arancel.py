"""Parse the Dominican Republic customs tariff (7th HS Amendment, 2022) into a PHP array.

Table layout produced by `pdftotext -layout`:

    CODIGO      DESIGNACION DE LA MERCANCIA          GRAV.   EX.ITBIS
    6109.10.00  - De algodon                           20
    4901.99.00  - - Los demas                           0        0

GRAV. is the duty rate (%). A 0 in EX.ITBIS marks goods exempt from ITBIS; a blank
column means the standard 18% applies.

Descriptions wrap across several lines and the rates land on the last line of the
block. A code with no rates at all is a subheading header, not a taxable line.

Usage:
    python3 parse_arancel.py <arancel.txt> <output.php>
"""

import json
import re
import sys

CODE_RE = re.compile(r'^(\s*)(\d{4}\.\d{2}\.\d{2})\s+(.*)$')

# A heading ("08.07  Melones, sandias...") hugs the margin: nowhere in the document
# does it exceed indent 8, while description continuations that happen to start with
# a cross-reference ("54.04 o 54.05, ...") sit at 9+.
HEADING_RE = re.compile(r'^\s{0,8}\d{2}\.\d{2}\s+\S')

# Rates live in the right margin. Requiring column >= 55 keeps a number that merely
# ends a description ("de mas de 100 anos") from being read as a rate.
RATE_MIN_COL = 55

# Page furniture only, anchored to the whole line: "Capitulo" and "Seccion" also
# appear INSIDE legitimate descriptions ("... de este Capitulo   20").
NOISE_RE = re.compile(
    r'^\s*(CÓDIGO\s+DESIGNACIÓN.*|EX\.|GRAV\.?|ARANCEL DE ADUANAS.*|\d{1,3}|'
    r'SISTEMA ARMONIZADO.*)\s*$',
    re.IGNORECASE,
)
SECTION_RE = re.compile(r'^\s*(Capítulo\s+\d+|SECCIÓN\s+[IVXL]+)\s*$', re.IGNORECASE)

# The appendices carry different tables (Ley 56-07, a flat 0% regime for the textile
# and footwear industry) that do not apply to consumer imports.
END_RE = re.compile(r'^\s*APENDICE\s+I\s*$', re.IGNORECASE)

VALID_RATES = {0, 2, 3, 8, 14, 20, 25, 30, 40, 87, 125, 500}


def extract_rates(line):
    """Return (duty, itbis_column, leading_text) or None if the line closes no rate."""
    m = re.search(r'\s{2,}(\d{1,3})(?:\s+(\d{1,3}))?\s*$', line)
    if not m or m.start(1) < RATE_MIN_COL:
        return None
    duty = int(m.group(1))
    itbis = int(m.group(2)) if m.group(2) is not None else None
    if duty not in VALID_RATES:
        return None
    return duty, itbis, line[:m.start()].rstrip()


def parse(path):
    lines = open(path, encoding='utf-8').read().splitlines()
    records = {}
    conflicts = []
    pending = None

    def flush(closing_line=None, rates=None):
        nonlocal pending
        if pending is None:
            return
        code, parts = pending
        pending = None
        if rates is None:
            return  # subheading header with no duty of its own
        if closing_line:
            parts.append(closing_line)
        duty, itbis = rates
        desc = re.sub(r'\s+', ' ', ' '.join(p.strip() for p in parts if p.strip())).strip()
        desc = desc.lstrip('- ').strip()
        rec = {'rate': duty, 'itbis_exempt': itbis == 0, 'name': desc}
        if code in records and records[code]['rate'] != duty:
            conflicts.append((code, records[code], rec))
        records.setdefault(code, rec)

    for raw in lines:
        if END_RE.match(raw):
            break

        # With a block open the rate wins over any header heuristic: descriptions end
        # in things like "... de este Capitulo   14" or "... de las partidas 84.40   8"
        # which would otherwise read as a chapter or heading change.
        if pending is not None:
            r = extract_rates(raw)
            if r:
                duty, itbis, remainder = r
                flush(closing_line=remainder, rates=(duty, itbis))
                continue

        if SECTION_RE.match(raw):
            flush()  # chapter change orphans whatever block was open
            continue
        if not raw.strip() or NOISE_RE.match(raw):
            continue

        m = CODE_RE.match(raw)
        if m:
            flush()  # the previous code never got a rate, so it was a header
            code, tail = m.group(2), m.group(3)
            r = extract_rates(raw)
            if r:
                duty, itbis, remainder = r
                desc = remainder[m.start(3):] if len(remainder) > m.start(3) else tail
                pending = (code, [desc])
                flush(rates=(duty, itbis))
            else:
                pending = (code, [tail])
            continue

        if pending is None:
            continue

        if HEADING_RE.match(raw):
            flush()
            continue

        pending[1].append(raw)

    flush()

    # pdftotext misaligns the GRAV. column in the 08.06 block: the description of
    # 0806.10.00 is split from its rate and 0806.20.00 ends up with none at all.
    # Verified against page 75 of the PDF: both grape lines pay 20%.
    records['0806.10.00'] = {'rate': 20, 'itbis_exempt': False, 'name': 'Frescas'}
    records['0806.20.00'] = {'rate': 20, 'itbis_exempt': False,
                             'name': 'Secas, incluidas las pasas'}

    return records, conflicts


def to_php(records):
    out = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '/*',
        ' * Customs tariff schedule of the Dominican Republic - 7th Amendment to the',
        ' * Harmonized System, 2022 edition (Direccion General de Aduanas).',
        ' *',
        ' * GENERATED FILE, do not edit by hand. Regenerate with:',
        ' *     scripts/arancel/regenerate.sh path/to/arancel.pdf',
        ' *',
        ' * rate         => duty rate (%) from the GRAV. column.',
        ' * itbis_exempt => the EX. ITBIS column carries 0 (goods exempt from ITBIS).',
        ' * name         => official Spanish description, kept verbatim from the schedule.',
        ' */',
        'return [',
    ]
    for code in sorted(records):
        r = records[code]
        name = r['name'].replace('\\', '\\\\').replace("'", "\\'")
        exempt = 'true' if r['itbis_exempt'] else 'false'
        out.append(f"    '{code}' => ['rate' => {r['rate']}, 'itbis_exempt' => {exempt}, 'name' => '{name}'],")
    out.append('];')
    return '\n'.join(out) + '\n'


def main():
    if len(sys.argv) != 3:
        print(__doc__)
        return 1

    source, target = sys.argv[1], sys.argv[2]
    records, conflicts = parse(source)

    text = open(source, encoding='utf-8').read().splitlines()
    end = next((i for i, l in enumerate(text) if END_RE.match(l)), len(text))
    seen = {m.group(1) for l in text[:end] if (m := re.match(r'^\s*(\d{4}\.\d{2}\.\d{2})', l))}
    missing = sorted(seen - set(records))

    rates = {}
    for r in records.values():
        rates[r['rate']] = rates.get(r['rate'], 0) + 1

    print(f'codes parsed     : {len(records)}')
    print(f'codes missing    : {len(missing)} {missing if missing else ""}')
    print(f'rate conflicts   : {len(conflicts)}')
    print(f'duty distribution: {dict(sorted(rates.items()))}')
    print(f'itbis exempt     : {sum(1 for r in records.values() if r["itbis_exempt"])}')
    print(f'chapters covered : {len({c[:2] for c in records})} (96 expected, ch. 77 is reserved)')

    if missing or conflicts:
        print('\nrefusing to write an incomplete schedule')
        return 1

    open(target, 'w', encoding='utf-8').write(to_php(records))
    json.dump(records, open(target.replace('.php', '.json'), 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'\nwrote: {target}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
