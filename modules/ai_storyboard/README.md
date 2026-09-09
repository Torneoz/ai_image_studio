# AI Storyboard

AI Storyboard turns a script into editable, production-aware shots and sends
individual frame prompts through AI Image Studio. It is an optional submodule
of AI Image Studio.

## Product direction

Research completed on 7 September 2026 found four recurring capabilities in
leading storyboard products:

1. Script-to-shot structure with editable action, dialogue, sound, and camera
   fields (Boords and StoryboardHero).
2. Persistent character, environment, prop, and brand references across shots
   (LTX Studio and StoryboardHero).
3. Selective shot regeneration and version retention instead of rebuilding a
   whole board (Boords and LTX Studio).
4. Timing, animatics, exports, comments, and approvals as the handoff layer
   between creative development and production (Boords, KROCK.IO, and
   Storyboarder).

The first release implements the foundation: structured AI breakdown,
editable continuity and character prompt bibles, production fields, shot timing and status, and
versioned frame generation backed by AI Image Studio. Generate All uses the
existing AI Image Studio Bulk Jobs facility for per-shot progress, results,
and error reporting. Generated keyframes can also be animated independently or
used in consecutive pairs to create configurable in-fill video sequences through
the same Bulk Jobs facility. Animatics, PDF/CSV
exports, annotations, and guest approval links are
the next logical increments.

Primary product sources:

- https://boords.com/ai-script-generator
- https://assets.boords.com/docs/script-to-storyboard
- https://ltx.io/blog/ltx-studio-tutorial
- https://storyboardhero.ai/features
- https://krock.io/features/
- https://wonderunit.com/storyboarder/

## Usage

Enable the module, grant **Access AI Storyboard**, then visit:

`/admin/content/ai-storyboard`

Create a project, paste a script, select configured chat and image models, and
run the breakdown. Review and edit each shot before generating frames because
provider calls may incur charges.

## Views integration

The module also supplies editable demo Views for **Locations**, **Characters**,
**Scenes**, and **Project Characters** under **AI Image Studio → Views**.
All base fields, filters, sorts and forward entity-reference relationships are
available. Projects have reverse relationships to their Scenes and Characters;
Scenes relate to their Shots. Scene and cast queries retain project ownership
checks. Update 10009 installs missing demo Views without overwriting existing
customizations.

Views can use **AI Storyboards** or **AI Storyboard shots** as base data
sources. Project views can relate to their shots, owners, and linked Image
Studio sessions. Shot views expose all production fields and can relate to
their project and generated Image Studio turn. Views queries enforce the same
owner-based access rules as the storyboard workspace.

Use **After prompt** for a final instruction that must be appended to every
frame prompt. This is useful for project-wide visual corrections or constraints
that should also apply when individual shots are regenerated.

Generated frames can be exported from the storyboard workspace as an ordered
ZIP image collection with a JSON shot manifest, or as an MP4 animatic. The MP4
uses each shot's configured duration and requires FFmpeg on the server.

## Reusable Locations, Characters and Scenes

- **Location library:** stable geography, architecture, materials, landmarks and
  reference images. Keep temporary weather/time/lighting in the Scene instead.
- **Character library:** canonical appearance, wardrobe, identity, voice
  description and reference images. Grant **Manage shared Storyboard Characters
  and Locations** to library editors; ordinary storyboard users can select and
  read library records without editing them.
- **Project characters:** a named cast member referencing a library Character,
  with a pinned version and project-specific appearance/voice overrides.
- **Scenes:** project-owned dramatic units with a scene number, script excerpt,
  pinned Location, location overrides, conditions, action/beats, selected project
  Characters and temporary character states. Use **Copy scene to a project** to
  reuse its setup without linking two productions' mutable scene state. Select
  the destination project and its cast separately; shots are not copied.
- **Shots:** ordered coverage within a Scene, optionally referencing a project
  Character as the dialogue speaker. Multiple speakers can still be identified
  in the dialogue text.

Every library save creates a new revision. A project use stores the selected
revision plus snapshots of its name, bible, voice and reference image IDs.
Library edits never silently change those snapshots. Edit the Scene or project
Character to select a historical version or explicitly refresh to the latest;
overrides remain unchanged. Library deletion is deliberately unavailable in the
UI to protect production references.

Create shared entries from **AI Storyboards → Location library / Character
library**, then manage Scenes and cast from the project's **Scenes and
characters** section. New script breakdowns draft Scenes and scene-local
Location Bibles. AI does not create or modify shared library entries: review
the draft and add reusable facts to the library yourself. Rebuilding shots
preserves existing Scenes (matched by scene number), including their library
choices and authored direction, while filling empty draft fields. It never
adds an inferred location override to a Scene with a chosen library Location.
Review those Scenes when the script changes.
Existing shots migrate into Scenes by project and scene number; existing
project bibles and generated media are preserved.

Both frame and video generation include pinned Location/Character prompts and
Scene overrides. Reference images are passed to frame generation for models
supporting AIIS multi-image input; other models receive the bible text. Video
generation uses the generated keyframe(s) as its visual references and the
same narrative text, including voice direction. A voice prompt does not clone
a voice or guarantee speaker identity. Bridge mode only pairs keyframes within
the same Scene; use animate mode at scene boundaries.

## Development checks

After applying database updates on a development site, run:

```sh
drush php:script web/modules/contrib/ai_image_studio/modules/ai_storyboard/tests/narrative-smoke.php
```

The suite exercises revisions, pinned text/images, forms, Views, ownership and
bridge boundaries. Its database fixtures are rolled back and it makes no paid
generation requests. An expected cross-project reference exception is logged
during the negative access test.
