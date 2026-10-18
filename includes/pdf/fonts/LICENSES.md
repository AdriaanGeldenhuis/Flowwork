# Document fonts

Latin subsets (Basic Latin, Latin-1, Latin Extended-A, common punctuation and
the currency symbols in `qi/lib/Currencies.php`) of open-source fonts, made
with `includes/pdf/TtfFont.php` (`subset()`). They are embedded — further
subset to the glyphs a document actually uses — in every quote, invoice and
credit note PDF (`qi/lib/DocPdf.php`). On screen the same families load from
Google Fonts.

| Files | Family | Licence |
|---|---|---|
| inter-* | Inter | SIL Open Font License 1.1 |
| manrope-* | Manrope | SIL Open Font License 1.1 |
| dm-sans-* | DM Sans | SIL Open Font License 1.1 |
| work-sans-* | Work Sans | SIL Open Font License 1.1 |
| montserrat-* | Montserrat | SIL Open Font License 1.1 |
| poppins-* | Poppins | SIL Open Font License 1.1 |
| lato-* | Lato | SIL Open Font License 1.1 |
| plex-sans-* | IBM Plex Sans | SIL Open Font License 1.1 |
| arimo-* | Arimo | SIL Open Font License 1.1 |
| newsreader-* | Newsreader | SIL Open Font License 1.1 |
| playfair-* | Playfair Display | SIL Open Font License 1.1 |
| lora-* | Lora | SIL Open Font License 1.1 |
| merriweather-* | Merriweather | SIL Open Font License 1.1 |
| cormorant-* | Cormorant Garamond | SIL Open Font License 1.1 |
| eb-garamond-* | EB Garamond | SIL Open Font License 1.1 |
| libre-baskerville-* | Libre Baskerville | SIL Open Font License 1.1 |
| gelasio-* | Gelasio | SIL Open Font License 1.1 |
| fallback-sans.ttf | DejaVu Sans (subset: Greek, Cyrillic, Arabic letters, currency, arrows, ticks) | Bitstream Vera licence; DejaVu changes public domain |

The SIL Open Font License 1.1 is at https://openfontlicense.org. Copyright of
each family remains with its authors (see the `name` table in each file).

DejaVu Sans — Copyright (c) 2003 by Bitstream, Inc. All Rights Reserved.
Bitstream Vera is a trademark of Bitstream, Inc. DejaVu changes are in the
public domain. Permission is hereby granted, free of charge, to any person
obtaining a copy of the fonts accompanying this license ("Fonts") and
associated documentation files (the "Font Software"), to reproduce and
distribute the Font Software, including without limitation the rights to use,
copy, merge, publish, distribute, and/or sell copies of the Font Software, and
to permit persons to whom the Font Software is furnished to do so, subject to
the following conditions: The above copyright and trademark notices and this
permission notice shall be included in all copies of one or more of the Font
Software typefaces. The Font Software may be modified, altered, or added to,
and in particular the designs of glyphs or characters in the Fonts may be
modified and additional glyphs or characters may be added to the Fonts, only
if the fonts are renamed to names not containing either the words "Bitstream"
or the word "Vera". This License becomes null and void to the extent
applicable to Fonts or Font Software that has been modified and is distributed
under the "Bitstream Vera" names. The Font Software may be sold as part of a
larger software package but no copy of one or more of the Font Software
typefaces may be sold by itself. THE FONT SOFTWARE IS PROVIDED "AS IS",
WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO
ANY WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT OF COPYRIGHT, PATENT, TRADEMARK, OR OTHER RIGHT. IN NO EVENT
SHALL BITSTREAM OR THE GNOME FOUNDATION BE LIABLE FOR ANY CLAIM, DAMAGES OR
OTHER LIABILITY, INCLUDING ANY GENERAL, SPECIAL, INDIRECT, INCIDENTAL, OR
CONSEQUENTIAL DAMAGES, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
ARISING FROM, OUT OF THE USE OF OR INABILITY TO USE THE FONT SOFTWARE OR FROM
OTHER DEALINGS IN THE FONT SOFTWARE.
