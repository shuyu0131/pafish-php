# AI Foundation

The built-in plugin stores multiple OpenAI Chat Completions compatible chat models, lets administrators test them, and exposes a provider-neutral text completion capability to other plugins. It is included in the release package and Docker image; do not copy individual core files into an installed site.

## Enable and configure

Open the **AI Foundation** entry under the administration system menu, enable
the bundled plugin if it is disabled, and open the Models tab. Add one or more
chat, embedding, image, or audio providers and choose a default model. The
plugin creates its five declared tables when it is activated; no manual SQL or
core migration is required.

Settings open on the Models tab. Opening settings does not call the model provider; connection checks run only when requested by the administrator.

Connection checks and the test form support chat models only. Checks update the current page and keep their result visible. Save/delete operations use the core notification helpers to retain messages after navigation.

Health probes use one bounded Chat Completions request and retain quota accounting. Valid reasoning-only or token-truncated responses confirm connectivity with a warning; they are not reported as network failures. Manual checks bypass cached results, and authentication, provider rate limits, invalid responses and network failures have separate messages.

Selecting a known provider fills its suggested API root when the field is empty or still contains the previous provider's preset. Custom endpoints are preserved and all suggested roots remain editable.

`purpose` is a preference for automatic chat-model selection, not an access restriction or an upstream API parameter. When no `modelId` is given, selection tries the requested purpose, then general-purpose models, then any model of the required type, preferring the default within each group. An explicit `modelId` must exist and match the requested model type; it never silently selects another model. Embedding, image and audio providers do not use purposes; their settings save as `general`.

## Plugin integration

An enabled plugin can resolve the capability after the extension system has booted:

```php
$complete = \Pafish\Services\ExtensionKernel::providerFor('plugin', 'ai_foundation', 'completeText');
if ($complete !== null) {
    $answer = $complete([
        ['role' => 'system', 'content' => 'You are a concise writing assistant.'],
        ['role' => 'user', 'content' => 'Suggest a title for this article.'],
    ], ['temperature' => 0.7, 'maxTokens' => 300]);
    // ['text' => '...', 'model' => '...', 'provider' => '...']
}
```

Without `modelId`, chat calls select by the requested purpose and configured fallback order; the default is preferred within the matching group. An explicit `modelId` is validated and does not silently fall back. The adapter supports synchronous and streaming chat completions, embeddings, image generation, speech-to-text, usage quotas, and optional Function Calling. Function Calling is restricted to the calling plugin by default; pass an explicit `allowedTools` list when a plugin needs a declared tool from another provider. Register mutating tools with the final `sideEffect=true` argument and pass `allowSideEffects=true` only from a deliberate server-side workflow; side-effect tools are rejected by default.

Chat admission reserves both input and maximum output tokens. Input uses a conservative UTF-8 byte estimate including message framing and tool schemas; long contexts can therefore be rejected before their actual token count reaches the configured limit. Every chat request sends an explicit output cap (the per-call setting, or 4,000 when that setting is unlimited, if `maxTokens` is omitted). Responses settle the provider's reported usage atomically; missing usage consumes the reservation. Function Calling settles and rechecks each model round, including its new tool results. With a positive site daily limit, `ai_chat` shares at most 25% with its outstanding reservations; a site limit of zero retains the unlimited setting.

## Verification and operations

See [verification notes](TESTING.md) before running plugin tests. Some scripts bootstrap the configured application and are not isolated from an installed site. Upstream latency, generated text and embedding dimensions depend on the actual provider/model, rather than a fixed benchmark. Preserve the original auth_secret when migrating encrypted credentials; do not log tokens or model secrets.
