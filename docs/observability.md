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

All AIIS image/video submissions honor the configured character limit centrally.
Storyboard projects have a saved `max_prompt_length`, initialized from Studio
settings. Current project values govern existing session submissions and retries;
ordinary sessions use Studio settings. There are no automatic model-specific
character or byte ceilings. Providers may still reject oversized input; AIIS
reports those errors rather than imposing its own provider cap. Chat/script
breakdown is separate from this media prompt setting.

AIIS keeps the saved prompt intact and budgets descriptive storyboard video
continuity at submission time. Action, dialogue, audio, camera, voices and overrides
are retained verbatim; complete descriptive sentences share the remaining space.
If those essentials cannot fit, generation fails locally without an API call.
The effective settings report `original_prompt_characters`,
`effective_prompt_characters`, `effective_prompt_limit` and
`prompt_context_compacted`. Settings also report `original_prompt_bytes` and
`effective_prompt_bytes` for diagnosis; `effective_prompt_byte_limit` is null.
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
