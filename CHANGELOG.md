# Changelog

All notable changes to AI Image Studio are documented in this file.

## 1.0.0-beta7

- Tightened the creation form layout and made source, model, and video controls
  collapse reliably when their start mode or output type is inactive.
- Made the image variation selector model-aware, with per-request limits of 10
  for Grok and GPT Image/DALL-E 2, one for DALL-E 3 and unknown models, and
  four for Imagen models.
- Added a distinct Grok reference-to-video mode with up to seven ordered
  reference images and validated `<IMAGE_N>` prompt tokens.
- Added native xAI video submission and delayed queue polling so workers do not
  remain occupied while Grok renders a video.
- Persisted provider request IDs, progress, terminal diagnostics, ordered input
  provenance, and provider-reported request costs.
- Added a Studio-owned post-response queue runner so video requests start and
  native provider polling advances without requiring Drupal cron. Cron remains
  a compatible fallback.
- Replaced Media autocomplete inputs with a standalone core Media Library
  control that does not depend on Media Library Form Element or another
  contributed module.
- Fixed Studio and settings forms under modules that serialize form objects,
  including Autosave Form, by making injected services available to Drupal's
  dependency serialization.
- Renamed the result publishing panel to Publish Media, clarified its publish
  action, and added a direct Download Media action beside it.
- Fixed Media Library dialog dependencies and immediate selected-item previews.

## 1.0.0-beta6

- Added ordered Grok multi-image editing with up to three source images through
  `/v1/images/edits`.
- Added session-version, Media Library, and upload reference controls with
  numbered chips, token insertion, and drag/drop ordering for session inputs.
- Added duplicate, access, format, provider capability, and input-count
  validation while preserving single-image behavior for other providers.

## 1.0.0-beta5

- Moved video generation to Drupal's queue by default so browser requests no
  longer remain open for long-running provider calls, with configurable retry
  limits and automatic Studio refresh while work is active.
- Added one-to-four image variation requests and retained every normalized
  provider output as a separately publishable version in a shared request
  group.
- Added Grok Imagine Image 2.0 quality controls and model-aware safeguards for
  Grok video resolution, including 1080p support on Video 1.5.
- Recognized both Grok- and xAI-named provider plugin IDs when mapping provider
  settings and cost estimates.
- Persisted provider request IDs, resolved model aliases, revised prompts,
  moderation results, output counts, and generation attempt counts for better
  provenance and diagnostics.
- Expanded turn states to distinguish queued, processing, expired, and
  cancelled generation work.

## 1.0.0-beta4

- Added complete Arabic, Simplified Chinese, French, German, Hindi, Japanese,
  Portuguese, Russian, Spanish, and Swahili interface translations.
- Made status, operation, cost-source, validation, and user-facing error text
  available to Drupal's translation system.
- Disabled permanent badge rendering when required server tools are unavailable
  and added clear guidance while keeping normal Media publishing available.
- Fixed access to private derived image and video files published to Media.

## 1.0.0-beta3

- Added PNG, JPEG, and WebP output selection, with PNG as the configurable
  default, for compatible image providers.
- Added optional, configurable AI badges to generated image and video previews.
- Added an option to permanently render the badge into a separate image or
  video file when publishing a result to Media, while preserving the original
  Studio asset.

## 1.0.0-beta2

- Fixed per-version Media publishing so the clicked result is saved instead of
  the first result in the session.
- Made refinements inherit the selected source turn's available model and
  generation parameters, including when changing the source without reloading.
- Validated the submitted model against the request operation before generation.
- Added compact image generation and publishing to image Media forms and Media
  Library dialogs, including compatibility with AI Media Image.

## 1.0.0-beta1

- Prevented users with view-any access from generating or publishing content
  in sessions they do not own.
- Enforced the configured session turn limit during server-side validation.
- Promoted the accumulated alpha feature set to the first beta release.

## 1.0.0-alpha6

- Added experimental text-to-video and image-to-video generation through
  operation-compatible Drupal AI providers.
- Added configurable video duration, aspect ratio, and resolution controls,
  inline MP4 playback, private-file authorization, and Video Media publishing.
- Added selection of any completed session image as the source for image
  refinement or image-to-video generation.
- Added immediate, accessible generation progress feedback and duplicate-submit
  protection for long-running image and video requests.
- Added a Saved to Media indicator to generated result cards.
- Expanded Studio settings with interface visibility, generation defaults,
  per-operation model overrides, upload and video limits, cost warnings,
  alternative-text policy, and provider diagnostics.
- Added provider-reported and estimated image/video cost details to version
  feedback and session reports, including configurable warning thresholds.
- Reordered session pages around generation, sorting, results, and reporting,
  and improved result-card controls and metadata presentation.
- Added a settings cog beside the New image session action on the session list.

## 1.0.0-alpha5

- Added a standard Drupal operations column to the image-session
  administration table.
- Added access-aware Open and Delete actions using Drupal’s compact operations
  dropdown.

## 1.0.0-alpha4

- Added a dedicated top-level AI Image Studio administration workspace with
  links to the session library, new-session form, and settings.
- Added an AI Image Studio link under AI configuration’s Search & Discovery
  section, immediately after AI API Explorer.
- Made session versions display newest first by default.
- Added a configurable “Default sort in Studio” setting with newest-first and
  oldest-first options.

## 1.0.0-alpha3

- Numbered version cards from one within each image session.
- Added concise prompt summaries to version-card headings.
- Added visual selection of any completed session image as the source for a
  new refinement branch.
- Made version headers more compact and added linked source-image references
  for refinement turns.
- Kept refinement-source radio controls aligned inside their version cards.
- Added concise, versioned Media names and editable alt-text suggestions.
- Made automatic aspect ratio and resolution inherit the source image’s
  dimensions when available, with clearer control descriptions.
- Added an oldest-first/newest-first display control above session versions.
- Added a tabular session report with per-version usage and cost details plus
  aggregate reported and estimated totals.

## 1.0.0-alpha2

- Fixed publishing an earlier image version to Media when the next-generation
  prompt is empty.
- Kept prompt validation scoped to image-generation submissions.

## 1.0.0-alpha1

- Added persistent, owner-scoped image-generation sessions.
- Added text-to-image generation and sequential image-to-image refinement.
- Added Drupal AI provider and model discovery.
- Added aspect-ratio, resolution, and transparent-background controls.
- Added request duration, token usage, and reported or estimated cost feedback.
- Added explicit publishing of selected versions to Drupal Media.
- Added private draft-file access control and session cleanup.
