# AI Observability integration

Enable AI Observability and its logging setting to receive AIIS lifecycle
records on the `ai_observability` logger channel. Existing logger backends receive
structured data under `metadata`. Filter by the `ai_image_studio` tag;
breakdown records also include `ai_storyboard`.

Coverage:

- Every persisted media-turn transition: queued, processing, completed, failed,
  expired, and retries. This includes standard AI provider calls, direct Grok
  multi-image/multi-variation calls and asynchronous reference-video requests.
- Changed polling progress/request IDs, without repeated identical pending logs.
- Individual variation outcomes, correlated by session, turn, request group,
  sequence, parent, replay source and provider request ID when available.
- Provider/model/operation, allowlisted generation settings, per-turn duration,
  reported/estimated cost and its source, and token usage when available.
- Storyboard breakdown queue/start/completion/failure, duration and merge counts.
- Wrapped HTTP failures: HTTP status, provider error code/type/parameter and
  exception class. Sanitized provider detail is retained on the failed turn.

The logging-enabled and tag filters apply to these application records. Input
logging is off by default: prompts and provider error prose are omitted unless
`log_input` is enabled, since errors can echo prompts. `log_output` adds generated
file IDs, never media binaries. Credentials, inline media and URLs are redacted;
arbitrary request settings, headers and provider responses are not logged.
Text is capped at 4,000 characters. Logging failures do not fail generation.

All AIIS image/video submissions honor `max_prompt_length` centrally, including
bulk jobs, storyboard media, replay and retries. For xAI's exact
`grok-imagine-video` model, the three video generation operations use the smaller
of that setting and the API-confirmed 4,096-character cap. Other models (including
1.5), image operations and providers do not inherit that cap: unknown provider
limits remain subject to API validation. Chat/script breakdown is a separate
operation, not limited by this image/video character setting.

AIIS keeps the saved prompt intact and budgets descriptive storyboard video
continuity at submission time. Action, dialogue, audio, camera, voices and overrides
are retained verbatim; complete descriptive sentences share the remaining space.
If those essentials cannot fit, generation fails locally without an API call.
The effective settings report `original_prompt_characters`,
`effective_prompt_characters`, `effective_prompt_limit` and
`prompt_context_compacted`. The exact `grok-imagine-video` model also enforces
a conservative 4,096 UTF-8 byte ceiling: a real request with 4,089 characters
but 4,101 bytes was rejected by that endpoint. The site setting remains measured
in characters. Both ceilings are applied while selecting complete continuity
sentences, without changing dialogue or splitting Unicode characters. Settings
also report `effective_prompt_byte_limit`, `original_prompt_bytes` and
`effective_prompt_bytes`; other models do not inherit this byte ceiling.
Oversized image prompts fail locally without silent
truncation. Existing asynchronous
jobs are polled without rebuilding their submitted prompt.

These are **application lifecycle logs**, not synthetic provider response events.
Native provider events and their OpenTelemetry integration remain unchanged;
AIIS does not duplicate token metrics or pretend failed/pending requests produced
a successful AI response. Direct custom requests gain lifecycle logs, not native
provider spans or guardrail coverage. Observability's provider-event class selector
controls native events, not these lifecycle records. Disabling logging or filtering
out `ai_image_studio` disables the additional records.

Existing generic failures cannot be reconstructed retrospectively. New attempts
capture available diagnostics. Reported turn duration follows existing AIIS
semantics (an async poll's processing duration, not total remote job wall time).
Logs describe saved state and may precede rollback of an enclosing transaction.

Regression checks (no paid API calls or saved fixtures):

```sh
drush php:script web/modules/contrib/ai_image_studio/tests/observability-smoke.php
```
