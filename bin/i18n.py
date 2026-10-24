"""
Builds the translation files.

Source of truth for translations: languages/src/<locale>.json (msgid -> translation,
plural forms as [singular, plural]). Formal variants only list the strings that differ
(<locale>.overrides.json on top of the base language).

Usage (from the plugin root, after `composer i18n:pot`):
    python bin/i18n.py
Then compile with wp-cli:  composer i18n:compile
"""
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
LANG = ROOT / 'languages'
STR = re.compile(r'"((?:[^"\\]|\\.)*)"')

# locale -> (base translations, optional overrides)
LOCALES = {
    'de_DE': ('de_DE.json', None),
    'de_DE_formal': ('de_DE.json', 'de_DE_formal.overrides.json'),
}


def unquote(chunk: str) -> str:
    raw = ''.join(STR.findall(chunk))
    return raw.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\')


def quote(text: str) -> str:
    return '"' + text.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'


def entries(pot: pathlib.Path):
    for block in pot.read_text(encoding='utf8').split('\n\n'):
        match = re.search(r'^msgid ((?:".*"\n?)+)', block, re.M)
        if not match:
            continue
        msgid = unquote(match.group(1))
        if not msgid:
            continue
        plural = re.search(r'^msgid_plural ((?:".*"\n?)+)', block, re.M)
        yield block, msgid, unquote(plural.group(1)) if plural else None


def write_po(locale: str, translations: dict) -> list:
    header = (
        'msgid ""\nmsgstr ""\n'
        '"Project-Id-Version: Portier\\n"\n'
        f'"Language: {locale}\\n"\n'
        '"MIME-Version: 1.0\\n"\n'
        '"Content-Type: text/plain; charset=UTF-8\\n"\n'
        '"Content-Transfer-Encoding: 8bit\\n"\n'
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n'
        '"X-Domain: portier\\n"\n'
    )
    parts, missing = [header], []
    for block, msgid, plural in entries(LANG / 'portier.pot'):
        comments = '\n'.join(line for line in block.split('\n') if line.startswith('#'))
        if msgid not in translations:
            missing.append(msgid)
            continue
        value = translations[msgid]
        entry = (comments + '\n' if comments else '') + 'msgid ' + quote(msgid) + '\n'
        if plural is not None:
            forms = value if isinstance(value, list) else [value, value]
            entry += 'msgid_plural ' + quote(plural) + '\n'
            entry += 'msgstr[0] ' + quote(forms[0]) + '\nmsgstr[1] ' + quote(forms[1]) + '\n'
        else:
            entry += 'msgstr ' + quote(value) + '\n'
        parts.append(entry)
    (LANG / f'portier-{locale}.po').write_text('\n'.join(parts), encoding='utf8', newline='\n')
    return missing


def main() -> int:
    status = 0
    for locale, (base, overrides) in LOCALES.items():
        translations = json.loads((LANG / 'src' / base).read_text(encoding='utf8'))
        if overrides:
            translations.update(json.loads((LANG / 'src' / overrides).read_text(encoding='utf8')))
        missing = write_po(locale, translations)
        print(f'{locale}: {len(missing)} missing')
        for msgid in missing:
            print('   ', msgid)
            status = 1
    return status


if __name__ == '__main__':
    sys.exit(main())
