# Changelog

All notable changes to AI Image Studio will be documented in this file.

## Unreleased

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
