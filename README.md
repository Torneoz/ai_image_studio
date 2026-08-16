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
- Inline video playback and publishing to a configured Video Media type.
- Provider and model selectors populated from Drupal AI configuration.
- Aspect ratio, resolution, quality, and other provider-supported controls.
- Up to four image variations per request when supported, retained as separate
  versions in the same request group.
- Queued video generation with configurable automatic retries, allowing users
  to leave the Studio page while cron or another queue runner processes work.
- Optional, configurable AI badges with the choice to embed them permanently
  in image or video files when publishing to Media.
- PNG, JPEG, and WebP output selection for compatible image providers.
- Version feedback for provider, model, request type, output settings,
  processing time, status, token usage, and reported or estimated cost.
- Media Library saving with alternative text required only when an image is
  saved as Media.
- Compact image generation directly in image Media forms and Media Library
  dialogs, with or without the AI Media Image module.
- Configurable generation defaults, limits, cost warnings, visibility controls,
  and per-operation model overrides.

Image requests run synchronously. Video requests are queued by default and are
processed by Drupal cron or another queue runner. Sites using video generation
must run cron regularly; administrators can restore synchronous video requests
from the module settings for development or provider troubleshooting.

Embedding badges in Media images requires PHP GD. Embedding badges in Media
videos additionally requires the `ffmpeg` executable to be available to PHP.

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
   can return up to four separately retained variations.
4. Enter follow-up prompts to refine or animate a selected image.
5. Review the version history and select the preferred result.
6. Save the preferred image or video to Media. Images require alternative text
   when that policy is enabled.

## Similar projects

AI Image Studio differs from one-shot image generators by preserving a
session-based refinement history, request metadata, usage information, and
cost feedback through the full editing workflow.

## Test harness

A test harness powered by DDEV can be found in the
[install-torneo-ddev GitHub repository](https://github.com/Torneoz/install-torneo-ddev).

## Roadmap

- Add video generation reference images (Grok).
- Better session forking.
- Improved Media integration.
- Improved reporting.
- Add native provider polling, cancellation, and queue progress reporting.
- More AI Providers.
- Session recording and playback.

## Contributing

Bug reports and feature requests belong in the
[Drupal.org issue queue](https://www.drupal.org/project/issues/ai_image_studio).
See [CONTRIBUTING.md](CONTRIBUTING.md) for development guidance.

## License

This project is licensed under GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
