# Script export

Saved storyboard projects have an **Export script** section with download buttons:

- JSON: versioned AIIS script snapshot (`format: ai_storyboard.script`, version 1),
  exact source script, basic typed paragraphs, project fields, ordered scenes and
  shots, and project cast including pinned library snapshots.
- Fountain: portable screenplay text with explicit element markers.
- Final Draft FDX: XML with typed screenplay paragraphs.
- Open Screenplay Format OSF 2.1: XML with paragraph styles.
- RTF: Courier text with screenplay element indentation and Unicode encoding.
- TXT: the original saved script, including its original line endings.

These are editable interchange exports, not native proprietary Movie Magic,
legacy FDR, Celtx or Fade In packages. FDX/Fountain/RTF are the interchange routes
for applications supporting those imports. PDF is a distribution format, not
included as an editable interchange export. No AI generation or paid requests
are performed.

Save edits before exporting. The script field is authoritative; generated shot
actions are not substituted into it. Basic screenplay layout and forced Fountain
scene/character/action/transition cues are recognized. Ambiguous prose falls back
to action; uppercase lines before speech are inferred as character cues. Advanced
Fountain constructs, rich-text emphasis, dual dialogue, title-page fields,
revision history, page locking, production numbering and exact pagination are
not reconstructed. Unknown markup may remain literal text. Review imported
formatting in your writing application. Native editor round-trip validation has
not been performed; tests validate serialization and element/text preservation.

JSON is a versioned export snapshot, not a new import/restore implementation.
Entity and file IDs are local references. Media binaries and live shared library
records are not bundled; pinned scene/cast snapshots are included. Entity/field
view access applies to snapshot data. Download routes require storyboard view
access, whitelist formats, use machine-name filenames, and disable caching.

Format references:

- [Fountain syntax](https://fountain.io/syntax/)
- [Open Screenplay Format 2.1 example/specification](https://github.com/severdia/Open-Screenplay-Format/blob/master/OSF-2.1.xml)
- [Final Draft user guide](https://www.finaldraft.com/downloads/manuals/final-draft-12-user-guide-mac-exported.pdf)
- [Fade In interchange support](https://www.fadeinpro.com/dl/page.pl?content=features)

Run `php modules/ai_storyboard/tests/script-export.php` for format checks and
`drush php:script web/modules/contrib/ai_image_studio/modules/ai_storyboard/tests/script-export-smoke.php`
for rollback-only Drupal download/snapshot/access checks.
