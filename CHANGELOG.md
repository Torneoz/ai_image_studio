# Changelog

All notable changes to AI Image Studio will be documented in this file.

## Unreleased

- Numbered version cards from one within each image session.
- Added concise prompt summaries to version-card headings.
- Added visual selection of any completed session image as the source for a
  new refinement branch.

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
