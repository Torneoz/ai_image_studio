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
versioned frame generation backed by AI Image Studio. Generate All creates a
durable bulk job with per-shot progress, results, and error reporting. Animatics, PDF/CSV
exports, reference-asset libraries, annotations, and guest approval links are
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
