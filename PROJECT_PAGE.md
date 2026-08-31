# AI Image Studio

AI Image Studio is a Drupal editorial workspace for generating images and
videos through providers configured with the Drupal AI module. It keeps every
generated asset, prompt, source, setting, status, usage figure, and cost record
inside a persistent session so editors can compare results, refine earlier
images, animate selected frames, and publish approved assets to Media.

The module is currently beta software. Test provider compatibility,
private-file delivery, queue processing, and Media field mappings in a
non-production environment before enabling it broadly.

## Highlights

- Generate images and videos with compatible Drupal AI providers and models.
- Preserve a chat-like session history instead of replacing earlier results.
- Refine any completed image or use it as the source for image-to-video.
- Use uploaded images, Media Library items, and earlier session results as
  generation inputs.
- Perform ordered Grok multi-image editing and reference-to-video generation.
- Chain video sequences using a completed video's last decodable frame.
- Join compatible completed session videos into a downloadable MP4 with
  FFmpeg.
- Request model-aware image variations and keep every returned version.
- Manage reusable start, style, after, video, and bulk-generation prompts with
  Drupal AI Prompt Management.
- Choose bundled visual styles including photographic, cinematic, illustrative,
  traditional-media, graphic, and 3D treatments.
- Apply optional RGB auto levels and configurable AI badges.
- Publish selected results to Drupal Media or download original files.
- Save or download all completed session results in one action.
- Generate images from image Media forms and Media Library dialogs.
- Review sessions, turns, settings, usage, costs, and provider diagnostics.
- Build reports with the supplied Views integration.

## Image and video workflows

Editors can start with text, an uploaded image, a Media Library image, or a
completed image from the current session. Compatible providers expose controls
for aspect ratio, resolution, quality, format, duration, and other supported
settings.

Image requests can return multiple retained versions. Sequential refinements
form branches from the selected source rather than destroying the earlier
result. Video requests are queued by default, with automatic retries and a
built-in post-response runner; Drupal cron remains a compatible fallback.

For supported Grok workflows, editors can order up to three image-editing
inputs or up to seven reference-to-video inputs. Reference-video prompts use
validated `<IMAGE_N>` tokens and retain ordered input provenance.

Completed videos can expose their final decodable frame as the starting image
for another video. A proof-of-concept session action can also join compatible
completed videos in oldest-first order.

## Prompt management

The editor writes the core request while reusable prompt entities supply
quality guidance, visual styles, after-prompts, and bulk-generation templates.
Compact selectors show the selected managed prompt over AJAX and retain direct
links for prompt administration and editing.

The module ships image and video fidelity prompts plus a visual-style library.
Other modules can add styles by shipping `ai.ai_prompt.*` configuration whose
type is `ai_image_studio_style`.

## Media integration

Preferred image and video results can be published to configured Media types.
Images can require editable alternative text at publishing time. Original
Studio files remain available for direct download, including after Media has
been created.

Optional AI badges can be shown in previews or permanently rendered into a
separate published file. Session actions can publish every completed result or
download all completed images and videos as a ZIP archive.

The compact Media workflow adds generation directly to image Media forms and
Media Library dialogs, with or without AI Media Image.

## Views and bulk content generation

Views can use AI Image Studio sessions or turns as base data sources. Supplied
Views expose relationships to sessions, parent turns, files, and Media, with
fields for previews, duration, cost, settings, token usage, and provider
metadata. Queries respect Studio ownership and view-any access.

The optional AI Image Studio VBO submodule integrates with Views Bulk
Operations. Its supplied **Image Studio Content** View lets editors select
nodes and queue one image request per item. Managed prompts and naming fields
support Drupal tokens such as `[node:title]`.

Bulk jobs can use an existing image, file, or Media reference as the source.
Completed images may remain in Studio, be published to Media, or be assigned
to a compatible image or image-Media reference field. The **Bulk image jobs**
area reports item status, attempts, cost, previews, errors, and result links,
with controls to regenerate one result or rerun a completed job using new
settings.

## Requirements

- Drupal 10.3 or Drupal 11.
- PHP 8.1 or later.
- Drupal AI 1.4 or later.
- A configured Drupal AI provider supporting the required operation.

Provider API usage may incur charges.

## Optional integrations and server tools

- Views Bulk Operations 4.4 or later enables bulk node-image generation.
- Token is required by the optional VBO submodule.
- PHP Imagick enables RGB auto levels.
- PHP GD enables permanent image-badge rendering.
- FFmpeg enables permanent video badges, video chaining-frame extraction, and
  joining compatible session videos.
- Torneo AI can provide provider-neutral cost estimates when an API does not
  report request cost.

## Installation

Install the module with Composer:

```shell
composer require drupal/ai_image_studio
```

Enable it and configure a compatible provider through Drupal AI:

```shell
drush en ai_image_studio
```

Settings are available at `/admin/config/ai/image-studio`.

For bulk generation, install Views Bulk Operations and enable the submodule:

```shell
composer require drupal/views_bulk_operations:^4.4 drupal/token
drush en ai_image_studio_vbo
```

## Project links

- Use the issue queue for bug reports and feature requests.
- Follow the project security-reporting policy for suspected vulnerabilities.
- Source code and contribution guidance are available in the project repository.
