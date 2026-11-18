<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Mistral\Ocr;

use Symfony\AI\Platform\Bridge\Mistral\Batch\JobClient;
use Symfony\AI\Platform\Bridge\Mistral\Ocr;
use Symfony\AI\Platform\Bridge\Mistral\Ocr\Result\Image;
use Symfony\AI\Platform\Bridge\Mistral\Ocr\Result\OcrResult;
use Symfony\AI\Platform\Bridge\Mistral\Ocr\Result\Page;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\JobResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * @author Tac Tacelosky <tacman@gmail.com>
 */
final class ResultConverter implements ResultConverterInterface
{
    use HttpStatusErrorHandlingTrait;

    public function __construct(private readonly string $provider = 'mistral')
    {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Ocr;
    }

    public function convert(RawResultInterface $result, array $options = []): ObjectResult|JobResult
    {
        if ($options[ModelClient::BATCH] ?? false) {
            return $this->startBatch($result, $options);
        }

        $httpResponse = $result->getObject();

        $this->throwOnHttpError($httpResponse);

        if (200 !== $httpResponse->getStatusCode()) {
            throw new RuntimeException(\sprintf('Unexpected response code %d: "%s"', $httpResponse->getStatusCode(), $httpResponse->getContent(false)));
        }

        return $this->convertData($result->getData());
    }

    /** @param array<string, mixed> $data */
    public function convertData(array $data): ObjectResult
    {
        if (!isset($data['pages']) || !\is_array($data['pages'])) {
            throw new RuntimeException('Response does not contain pages.');
        }

        $pages = array_map(static function (array $page): Page {
            $images = array_map(static fn (array $image): Image => new Image(
                $image['id'] ?? '',
                $image['top_left_x'] ?? null,
                $image['top_left_y'] ?? null,
                $image['bottom_right_x'] ?? null,
                $image['bottom_right_y'] ?? null,
                $image['image_base64'] ?? null,
            ), $page['images'] ?? []);

            return new Page(
                $page['index'] ?? 0,
                $page['markdown'] ?? '',
                $images,
                $page['dimensions'] ?? null,
            );
        }, $data['pages']);

        return new ObjectResult(new OcrResult(
            $pages,
            $data['model'] ?? '',
            $data['usage_info'] ?? null,
            $data['document_annotation'] ?? null,
        ));
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }

    /** @param array<string, mixed> $options */
    private function startBatch(RawResultInterface|RawHttpResult $result, array $options): JobResult
    {
        $this->throwOnHttpError($result->getObject());

        $data = $result->getData();
        $id = $data['id'] ?? null;

        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('The Mistral response does not contain a batch identifier.');
        }

        $endpoint = $data['endpoint'] ?? null;
        $timeoutHours = $options[ModelClient::TIMEOUT_HOURS] ?? null;

        // The job states the timeout it was asked for nowhere in its own representation, so the
        // longest the batch may take is carried over from the request that started it.
        $maxDuration = \is_int($timeoutHours) && 0 < $timeoutHours ? $timeoutHours * 3600 : JobClient::DEFAULT_MAX_DURATION;

        return new JobResult(new JobHandle($id, [
            'kind' => JobClient::KIND,
            'endpoint' => \is_string($endpoint) ? $endpoint : ModelClient::PATH,
        ], $this->provider, $maxDuration, JobClient::DEFAULT_POLL_INTERVAL));
    }
}
