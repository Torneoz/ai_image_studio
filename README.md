# AI Image Studio

> **Beta:** Test provider compatibility, private-file delivery, and Media field
> mappings in a non-production environment before enabling access broadly.

AI Image Studio provides a conversational workspace for generating images and
videos and refining images through sequential prompts. Each generated version
remains available in the session so editors can compare results, branch from
an earlier image, animate a selected image, and save preferred results to
Drupal Media.

## Features

- Persistent image sessions with a chat-like prompt and version history.
- Text-to-image generation through compatible Drupal AI providers.
- Sequential image refinement when the selected provider and model support
  image-to-image requests.
- Text-to-video and image-to-video generation through compatible providers.
- Ordered Grok image editing with up to three uploaded, Media, or prior-session
  images and retained input provenance.
- Grok reference-to-video with up to seven ordered images, `<IMAGE_N>` prompt
  tokens, and native submit/poll queue processing.
- Core Media Library selection for starting and reference images without a
  dependency on a contributed Media Library form-element module.
- Inline video playback and publishing to a configured Video Media type.
- Provider and model selectors populated from Drupal AI configuration.
- Aspect ratio, resolution, quality, and other provider-supported controls.
- Model-aware image variation counts, including up to ten results for Grok,
  GPT Image, and DALL-E 2, retained as separate versions in the same request
  group.
- Queued video generation with configurable automatic retries and a built-in
  post-response runner; Drupal cron remains an optional fallback.
- Optional, configurable AI badges with the choice to embed them permanently
  in image or video files when publishing to Media.
- PNG, JPEG, and WebP output selection for compatible image providers.
- Optional RGB auto levels for generated images, with a configurable default.
- Version feedback for provider, model, request type, output settings,
  processing time, status, token usage, and reported or estimated cost.
- Media Library saving with alternative text required only when an image is
  saved as Media.
- Direct image and video downloads from each unpublished result's Publish Media
  panel.
- Session-wide actions to save every completed result to Media or download all
  completed images and videos as a ZIP archive.
- Compact image generation directly in image Media forms and Media Library
  dialogs, with or without the AI Media Image module.
- Configurable generation defaults, limits, cost warnings, visibility controls,
  and per-operation model overrides.
- Reusable prompts managed through Drupal AI Prompt Management in the full
  Studio, sequential refinement, video regeneration, and compact Media forms.

Image requests run synchronously. Video requests are queued by default. Studio
starts one queued item after each web response and its five-second status
refresh advances native provider polling. Drupal cron or another queue runner
can process the same queue as a fallback, but is not required to start videos.
Administrators can restore synchronous video requests from the module settings
for development or provider troubleshooting.

Embedding badges in Media images requires PHP GD. Embedding badges in Media
videos additionally requires the `ffmpeg` executable to be available to PHP.
Applying auto levels requires the PHP Imagick extension.

## Requirements

- PHP 8.1 or later.
- Drupal 10.3 or Drupal 11.
- [AI](https://www.drupal.org/project/ai) 1.4 or later.
- At least one Drupal AI provider supporting the operation you want to use.

Sequential refinement also requires a provider and model capable of accepting
an image as input. Provider API usage may incur charges.

## Installation

Install with Composer:

```shell
composer require drupal/ai_image_studio
```

Enable the module:

```shell
drush en ai_image_studio
```

Configure a compatible provider through Drupal AI before creating a session.
Core File, Media, and Media Library are enabled as module dependencies. No
additional Media Library widget module is required.

## Configuration

Module settings are available at:

```text
/admin/config/ai/image-studio
```

The studio is available from the Drupal administration interface to users with
the module's access permission.

## Usage

1. Create a Studio session.
2. Select a provider and model exposed by Drupal AI.
3. Choose image or video output and submit an initial prompt. Image requests
   offer only the variation counts supported by the selected model.
4. For Grok image editing, add and order up to three session, Media Library, or
   uploaded inputs.
5. For Grok reference video, choose Generate from references, order up to seven
   images, and identify them in the prompt with `<IMAGE_N>` tokens.
6. Enter follow-up prompts to refine or animate a selected image.
7. Review the version history and select the preferred result.
8. Open Publish Media to download the original result or publish it to Drupal
   Media, or use the session actions to save or download every result. Images
   require alternative text when that policy is enabled.

## Views integration

Views can use **AI Image Studio sessions** or **AI Image Studio turns** as its
base data source. Session views include a **Session turns** relationship, while
turn views can relate to their session, parent turn, files, and published
Media. Turn fields include readable generation duration and estimated cost,
plus safe JSON displays for generation settings, token usage, and provider
metadata.

Views queries follow Studio access: administrators and users with the
**View any AI Image Studio session** permission can see all records; other
Studio users see only their own sessions and turns.

## Views Bulk Operations integration

The optional AI Image Studio VBO submodule adds a **Generate images with AI
Image Studio** action to Views Bulk Operations node views. Install Views Bulk
Operations, enable `ai_image_studio_vbo`, and add the action to a View's
**Global: Views bulk operations** field.

Editors select or create reusable prompts through Drupal AI Prompt Management.
Prompts can contain Drupal node tokens, and each job snapshots the selected
prompt text before token replacement. Editors can optionally take an image from
an image, file, or Media reference field and publish completed results to Media.
The VBO request only snapshots and queues the selected nodes; Drupal cron
performs the provider requests in the background. Job progress is available
under **Content > Bulk image jobs**.

Install the optional dependency with Composer:

```shell
composer require drupal/views_bulk_operations:^4.4
```

## Similar projects

AI Image Studio differs from one-shot image generators by preserving a
session-based refinement history, request metadata, usage information, and
cost feedback through the full editing workflow.

## Test harness

A test harness powered by DDEV can be found in the
[install-torneo-ddev GitHub repository](https://github.com/Torneoz/install-torneo-ddev).

## Roadmap

- Better session forking.
- Improved Media integration.
- Improved reporting.
- Add provider cancellation and richer queue progress reporting.
- More AI Providers.
- Session recording and playback.

## Contributing

Bug reports and feature requests belong in the
[Drupal.org issue queue](https://www.drupal.org/project/issues/ai_image_studio).
See [CONTRIBUTING.md](CONTRIBUTING.md) for development guidance.

## License

This project is licensed under GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
