Mistral Platform
================

Mistral platform bridge for Symfony AI.

Mistral Documentation
---------------------

 * [Batch processing](https://docs.mistral.ai/inference/batch-processing)
 * [API reference](https://docs.mistral.ai/api/)
 * [Chat completions](https://docs.mistral.ai/api/endpoint/chat)
 * [OCR](https://docs.mistral.ai/api/endpoint/ocr)
 * [Speech to Text (Voxtral)](https://docs.mistral.ai/api/endpoint/audio_transcriptions)
 * [Text to Speech (Voxtral)](https://docs.mistral.ai/studio/audio/text-to-speech/overview)
 * [Speech endpoint](https://docs.mistral.ai/api/endpoint/audio/speech)


Test Fixtures
-------------

The test fixtures in `Tests/Fixtures/` contain binary media content with the following owners and licenses:

 * `document.pdf`: Chem8240ja, Public Domain, see [Wikipedia](https://en.m.wikipedia.org/wiki/File:Re_example.pdf)

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/ai/issues) and
   [send Pull Requests](https://github.com/symfony/ai/pulls)
   in the [main Symfony AI repository](https://github.com/symfony/ai)

Batch requests
--------------

Pass keyed inputs and `batch: true` to the same platform used for synchronous
requests. Chat accepts message bags; OCR accepts image or document content:

```php
use Symfony\AI\Platform\Bridge\Mistral\Factory;
use Symfony\AI\Platform\Message\Content\ImageUrl;

$platform = Factory::createPlatform($apiKey);
$handle = $platform->invoke('mistral-ocr-latest', [
    'page-1' => new ImageUrl('https://example.org/page-1.jpg'),
    'page-2' => new ImageUrl('https://example.org/page-2.jpg'),
], ['batch' => true])->asJob();

// Persist $handle->toArray(), then resume it in a later worker.
$client = Factory::createJobClient($apiKey);
$status = $client->getStatus($handle);
if ($status->isTerminal()) {
    foreach ($client->getResult($handle)->getContent() as $item) {
        // Match by $item->getId(); output order need not match input order.
        if ($item->isSuccess()) {
            $ocr = $item->getResult()->getContent();
            echo $ocr->getMarkdown();
        }
    }
}
```

Requests use an uploaded JSONL file. Streaming is not supported. `getProgress()` reports job status and request
counts in one request; `cancel()` requests cancellation. Canceled and expired jobs
may still have partial results. Retrieving a job with no available results throws.
Result iteration is one-shot; an invalid JSONL record throws instead of silently
omitting an input. Per-request API or conversion errors remain individual failures.
OCR items use the existing OCR result objects; raw layout fields not represented by
those objects require a separate raw response archive. Persistence, scheduling,
retry decisions, and applying results belong to the application.
