# AI Image Studio 1.0.0-beta9

AI Image Studio 1.0.0-beta9 expands the module from an individual generation
workspace into a broader editorial asset-production system. This release adds
managed visual styles, Views reporting, optional bulk generation for selected
content, video regeneration and sequence tools, and image-processing controls.

This remains a beta release. Test provider behaviour, private files, queue
processing, FFmpeg and Imagick availability, and content-field destinations in
a non-production environment before deployment.

## Highlights

### Managed prompts and visual styles

- Added a dedicated managed visual-style prompt library shared by Studio and
  compact Media workflows.
- Added bundled photographic, illustrative, traditional-media, graphic, and
  3D styles.
- Added managed high- and low-quality image prompts and high- and low-fidelity
  video prompts.
- Replaced large Style and After Prompt tables with compact selectors that
  load a preview of the selected prompt over AJAX.
- Kept direct controls for managing prompts and editing the current selection.
- Applied managed prompts to initial generation, refinement, video
  regeneration, compact Media, and VBO workflows.
- Normalized nested prompt-selector submissions so generation receives stable
  scalar prompt IDs.
- Prevented missing prompt-type configuration from crashing Studio, Media
  Library, and VBO forms.
- Added install and update handling that restores shipped prompt configuration
  and Views when recipe discovery cannot find cross-provider configuration.

### Views reporting

- Added AI Image Studio sessions and turns as Views base tables.
- Added supplied administrative Session and Turn views.
- Added relationships between sessions, turns, parent turns, files, and
  published Media.
- Added readable fields for generation duration and estimated cost.
- Added safe displays for generation settings, token usage, and provider
  metadata.
- Added compact image and video previews.
- Applied Studio owner and view-any access rules to Views queries.

### Bulk image generation

- Added the optional `ai_image_studio_vbo` submodule for Views Bulk Operations.
- Added a supplied **Image Studio Content** View for filtering and selecting
  nodes.
- Added the **Generate images with AI Image Studio** VBO action.
- Added reusable, token-aware bulk prompt templates with snapshotting of the
  selected prompt and settings when a job is created.
- Added optional image-to-image generation from node image, file, or Media
  reference fields, with text-to-image fallback when no usable source exists.
- Added model, aspect ratio, resolution, quality, file type, badge, Media name,
  and alternative-text controls.
- Added optional automatic Media publishing.
- Added bundle-aware result destinations for native image fields and image
  Media-reference fields. Assigning a generated result replaces the existing
  destination value.
- Added owner-aware **Bulk image jobs** listings and item-level status,
  attempts, costs, previews, result links, and error messages.
- Added regeneration of an individual item and rerunning a completed job with
  new settings.
- Added active-page queue advancement while retaining Drupal cron as the
  unattended fallback.
- Fixed VBO action discovery on Drupal 11 and ensured supplied View
  configuration is owned by the integration submodule.

### Video workflows

- Added regeneration of completed videos using their original inputs, updated
  settings, and an optional replacement prompt.
- Added sequence chaining by extracting the final decodable frame from a
  completed video and making it available as the next image-to-video source.
- Added access-controlled private previews for extracted chaining frames.
- Added clear reporting when a chaining frame cannot be produced or accessed.
- Added a proof-of-concept session action that joins compatible completed
  videos in oldest-first order into a downloadable MP4 using FFmpeg.
- Fixed dynamically labelled video submit buttons that could otherwise stop
  before Drupal validation without displaying an error.

### Image processing and interface improvements

- Added optional RGB auto levels for generated images using Imagick.
- Added an administrator-configurable auto-level default.
- Tightened creation-form layout and improved conditional visibility for
  source, model, and video controls.
- Added asset-specific image and video action labels and badges.
- Reduced result-card media size and improved generated-result focus during
  processing.
- Kept image generation controls at their form position during refreshes.
- Linked refinement previews to their source results.
- Displayed the starting frame used by video results.

## Requirements and optional dependencies

The main module now explicitly depends on Drupal core Views and Options, in
addition to its existing File, Media, Media Library, User, and Drupal AI
requirements.

Optional capabilities require:

- `drupal/views_bulk_operations:^4.4` and Token for the VBO submodule.
- PHP Imagick for RGB auto levels.
- PHP GD for permanent image badges.
- FFmpeg for permanent video badges, chaining-frame extraction, and joined
  video downloads.

## Upgrade notes

1. Back up the site and deploy the new code.
2. Run Drupal database updates so new schema, prompt types, prompts, Views, and
   settings are installed:

   ```shell
   drush updb
   drush cr
   ```

3. Review permissions for Studio Views and bulk-job access.
4. If bulk generation is required, install Views Bulk Operations and Token,
   then enable `ai_image_studio_vbo`.
5. Review the supplied Image Studio Content View before exposing it to editors.
6. Test destination mappings carefully: attaching a bulk result replaces the
   existing value in the selected image or Media-reference field.
7. Confirm cron is running for unattended bulk processing. An open active job
   page can advance the queue, but it should not replace a healthy cron setup.
8. Confirm PHP can execute FFmpeg before enabling video frame chaining or
   joined downloads, and confirm Imagick before enabling auto levels.

## Known constraints

- This is beta software and provider capabilities vary by plugin and model.
- Joined videos must be mutually compatible; the join action is explicitly a
  proof of concept.
- Image requests in the interactive Studio run synchronously. Video and bulk
  requests use queues by default.
- The supplied bulk action generates one result per selected node.
- Bulk attachment replaces the selected destination field's existing value.
- Provider API requests may incur costs.

## Change size

Compared with `1.0.0-beta8`, this release contains 52 commits across 80 files,
with approximately 6,559 additions and 130 deletions.
