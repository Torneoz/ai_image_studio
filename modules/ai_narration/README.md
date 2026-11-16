# AI Narration

AI Narration adds a small, provider-neutral text-to-speech workflow to AI Image Studio.

## Design

The module deliberately delegates voice selection and synthesis to Drupal AI's
`text_to_speech` operation. This keeps API keys, provider-specific voice lists,
and provider transport behavior in the provider modules where they belong.

`NarrationGenerator` is the reusable application boundary: it validates the
selected provider/voice, invokes the operation, checks that audio was returned,
and persists a managed permanent file in `public://ai-narration`. The form only
collects text and renders the resulting download link, so future callers (a
queue worker, a node action, or Storyboard integration) can reuse the same
service without duplicating TTS logic.

Narration projects own a voice selection and an explicit per-request character
limit. Chapters are ordered records belonging to a project and retain their
source text, generation state, errors, and generated chunk files. Always set
the project limit below the provider/voice limit: `NarrationTextChunker` splits
at sentence boundaries and only falls back to words or long-token slices when
needed, so a request never exceeds the stored maximum.

## Setup

1. Enable `ai_narration` and a Drupal AI provider supporting `text_to_speech`.
2. Configure the provider and voice in Drupal AI.
3. Grant **Generate AI narration** and visit **Content → AI Narration**.

Generated audio is a permanent managed public file; review public-file and
provider retention policies before using sensitive text.
