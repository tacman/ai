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

use Symfony\AI\Platform\Bridge\Mistral\Ocr;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Tac Tacelosky <tacman@gmail.com>
 */
final class ModelClient implements ModelClientInterface
{
    public const BATCH = 'batch';
    public const TIMEOUT_HOURS = 'timeout_hours';
    public const PATH = '/v1/ocr';
    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
        string $baseUrl = 'https://api.mistral.ai',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Ocr;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('The payload must be an array of normalized document content, but a string was given to "%s".', self::class));
        }

        if ($options['batch'] ?? false) {
            unset($options['batch']);
            if ([] === $payload || ($options['stream'] ?? false)) {
                throw new InvalidArgumentException('A batch requires non-empty keyed inputs and cannot be streamed.');
            }
            $timeoutHours = $options[self::TIMEOUT_HOURS] ?? null;
            unset($options[self::TIMEOUT_HOURS]);
            if (null !== $timeoutHours && (!\is_int($timeoutHours) || $timeoutHours < 1)) {
                throw new InvalidArgumentException('Batch timeout must be a positive integer number of hours.');
            }
            $requests = [];
            foreach ($payload as $id => $input) {
                if (!\is_array($input) || !\in_array($input['type'] ?? null, ['image_url', 'document_url'], true)) {
                    throw new InvalidArgumentException('Each OCR batch input must normalize to a document or image URL.');
                }
                $requests[$id] = array_merge($options, ['document' => $input]);
            }

            return (new \Symfony\AI\Platform\Bridge\Mistral\Batch\BatchClient($this->httpClient, $this->apiKey, $this->baseUrl, self::PATH))->submit($model->getName(), $requests, $timeoutHours);
        }
        unset($options['batch']);

        return new RawHttpResult($this->httpClient->request('POST', $this->baseUrl.'/v1/ocr', [
            'auth_bearer' => $this->apiKey,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => array_merge($options, [
                'model' => $model->getName(),
                'document' => $payload,
            ]),
        ]));
    }
}
