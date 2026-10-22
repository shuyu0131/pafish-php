# AI Foundation

The built-in plugin stores multiple OpenAI Chat Completions compatible chat models, lets administrators test them, and exposes a provider-neutral text completion capability to other plugins.

## Installing on Pafish 1.2.3

Use the current `app/Services/OutboundHttp.php` from the core alongside this plugin. Its request API adds the response-chunk callback required for streaming and multipart bodies required for audio transcription. The extension schema, lifecycle, routes and custom settings APIs already exist in 1.2.3; no core database migration is needed for this patch. Activation creates the plugin's five declared tables.

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

Calls use the default model unless `modelId` is supplied. The adapter supports synchronous and streaming chat completions, embeddings, image generation, speech-to-text, usage quotas, and optional Function Calling. Function Calling is restricted to the calling plugin by default; pass an explicit `allowedTools` list when a plugin needs a declared tool from another provider. Register mutating tools with the final `sideEffect=true` argument and pass `allowSideEffects=true` only from a deliberate server-side workflow; side-effect tools are rejected by default.
