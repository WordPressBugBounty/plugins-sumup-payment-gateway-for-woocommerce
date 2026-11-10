"""Regression checks for catalog validation; run with python -m unittest discover -s scripts."""
import contextlib
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import polib

import i18n
from i18n import DOMAIN, run, validate


class CatalogValidationTest(unittest.TestCase):
    def test_missing_and_fuzzy_translations_fail_independently(self):
        catalog = polib.POFile()
        catalog.append(polib.POEntry(msgid='Empty'))
        catalog.append(polib.POEntry(msgid='Fuzzy', msgstr='Flou', flags=['fuzzy']))
        self.assertEqual(2, len(validate(catalog)))

    def test_link_targets_and_balanced_markup_are_preserved(self):
        catalog = polib.POFile()
        entry = polib.POEntry(msgid='Open <a href="%s">settings</a>.',
                             msgstr='<a href="%s">Paramètres</a> ouverts.')
        catalog.append(entry)
        self.assertEqual([], validate(catalog))
        entry.msgstr = '<a href="https://example.com">Paramètres</a>'
        self.assertTrue(validate(catalog))
        entry.msgstr = '<a href="%s">Paramètres'
        self.assertTrue(validate(catalog))


class CatalogPipelineTest(unittest.TestCase):
    def test_rebuilding_does_not_hide_missing_text_or_invalid_formats(self):
        template = polib.POFile()
        template.append(polib.POEntry(msgid='Payment %s', flags=['php-format']))
        with tempfile.TemporaryDirectory() as directory:
            languages = Path(directory)
            path = languages / f'{DOMAIN}-de_DE.po'
            polib.POFile().save(str(path))
            with patch.object(i18n, 'LANGUAGES', languages), patch.object(i18n, 'extract', return_value=template):
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as errors:
                    self.assertEqual(0, run('update', []))
                    self.assertEqual(1, run('check', []))
                    self.assertIn('Missing or fuzzy', errors.getvalue())
                    self.assertNotIn('is stale', errors.getvalue())
                    catalog = polib.pofile(str(path), wrapwidth=0)
                    catalog[0].msgstr = 'Zahlung %s'
                    catalog.save(str(path))
                    self.assertEqual(0, run('update', []))
                    self.assertEqual(0, run('check', []))
                    path.with_suffix('.mo').write_bytes(b'stale')
                    self.assertEqual(1, run('check', []))
                    self.assertIn('MO is stale', errors.getvalue())
                    catalog[0].msgstr = 'Zahlung %d'
                    catalog.save(str(path))
                    self.assertEqual(0, run('update', []))
                    self.assertEqual(1, run('check', []))
                    self.assertIn('format', errors.getvalue())


if __name__ == '__main__':
    unittest.main()
