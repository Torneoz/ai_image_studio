# Optional AI Metering integration assessment

This is an implementation proposal, not an enabled Studio metering adapter.
The DDEV site inspected has Drupal AI 1.5.0-rc3 and AI Metering 1.0.2 enabled.
Findings below refer to its installed code; validate the supported version range
before shipping an optional integration.

## What already works

AI Metering subscribes to Drupal AI's `PreGenerateResponseEvent`,
`PostGenerateResponseEvent` and `PostStreamingResponseEvent`. The pre-event
checks the current account's permissions and monthly token quota. The post-event
records provider, model, operation, tokens, estimated USD cost and latency.
Streaming chat has its own duplicate-count prevention.

Studio's ordinary provider calls run through Drupal AI's provider proxy and
already carry the `ai_image_studio` tag. They can therefore reach these
subscribers without adding another subscriber for the same billable call.
Storyboard script/LLM work should use this same native path where possible.

However, event coverage is not the same as accurate media accounting. Metering's
post subscriber extracts tokens or estimates them from text; it skips responses
with no usable token data and calculates cost using token pricing. A visible
image/video row may therefore still have an inappropriate estimate. Image count,
resolution, quality and video duration need media-aware pricing.

## Gaps in this suite

- Studio's custom Grok multi-image/multi-variation and reference-video paths call
  HTTP directly. They bypass the provider proxy's pre/post/error events, including
  its quota checks. AI Observability lifecycle logging does not fill that gap:
  it writes diagnostic records, not AI Metering usage entries.
- Queue workers currently do not establish the initiating editor as the current
  account for provider execution. Metering reads `current_user`, so cron or
  background work can be assigned to the runner or uid 0. Capture the requester
  per request/job; session ownership alone is insufficient in shared sessions.
- Async submissions, status polling, retries and multiple output turns need one
  accounting identity per provider attempt. Polls and per-output bookkeeping must
  not create duplicate charges. A fresh billable retry is a separate attempt.
- Studio already stores provider-reported or estimated media costs and their
  provenance. Those should be used rather than converting media spend into
  invented token counts. Missing cost must remain unknown, not silently zero.
- Metering's installed `QuotaManager::logUsage()` accepts explicit USD cost, uid,
  context UUID, caller and latency, so a media adapter is feasible. Its current
  quota consumption is token-based, however: logging a zero-token video cost does
  not create a money-based spending limit. Cost budgets need a separate design.

## Proposed approach

1. Add an optional `ai_image_studio_metering` submodule depending on Studio and
   AI Metering. Keep the main module usable without Metering and do not auto-enable
   the integration or grant permissions.
2. Attach `aim_feature:ai_image_studio` / `aim_feature:ai_storyboard` and an
   `aim_context:<session-or-project-UUID>` tag to native calls. Keep turn, job,
   request-group and provider-request identifiers in a correlation record.
3. Execute queued provider calls in the initiating account's scope with
   `account_switcher`, restoring the original account in `finally`. Apply the same
   policy to the direct HTTP path. Do not silently send an unsupported image/video
   operation to a text-only quota fallback such as Ollama.
4. Prefer moving direct HTTP operations behind proper provider operations so
   Drupal AI owns preflight, post-response and exception dispatch. If an adapter
   is needed temporarily, implement the actual event contract, including vetoes,
   forced responses and exceptions; merely emitting notification events would
   not enforce the preflight decision.
5. Use one media accounting owner. Extend Metering's media handling upstream or
   coordinate an explicit adapter so native events and turn-completion handling
   cannot both log the same call. Use a durable unique attempt key and transaction
   or lock; `logUsage()` currently inserts a row without an external deduplication
   key. Reuse its public service rather than writing its tables directly.
6. Record media cost only when reported or defensibly estimated, retain real
   token counts separately, and preserve cost provenance. The record-alter hook
   can adjust existing columns, but cannot store arbitrary extra fields unless
   the schema is extended. Failures need their own outcome handling; the current
   log method writes `status = completed`.

Validate with Metering absent and present; native and direct provider paths;
quota denial; correct initiating-user attribution; zero-token media; reported
versus estimated/unknown cost; async polling; concurrent retries; multi-output
requests; and one usage record per actual billable attempt. Stub providers for
these tests to avoid real charges.

## Source references

- [AI Metering project](https://www.drupal.org/project/ai_metering)
- Installed Metering: `src/EventSubscriber/AiPreGenerateSubscriber.php`,
  `src/EventSubscriber/AiPostGenerateSubscriber.php`,
  `src/Service/QuotaManager.php`, and `ai_metering.api.php`.
- Installed Drupal AI: `src/Plugin/ProviderProxy.php` and `src/Event/`.
- Studio: `src/Service/ImageGenerator.php`,
  `src/Service/GenerationObservability.php`, and the suite's queue workers.
