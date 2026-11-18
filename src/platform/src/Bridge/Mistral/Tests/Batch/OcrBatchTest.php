<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Mistral\Tests\Batch;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Mistral\Batch\JobClient;
use Symfony\AI\Platform\Bridge\Mistral\Factory;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** @author Tac Tacelosky <tacman@gmail.com> */
final class OcrBatchTest extends TestCase
{
    public function testOcrBatchUsesCustomHostAndPersistsTimeout()
    {
        $http = new MockHttpClient([
            function ($method, $url, $options) {
                $this->assertSame('https://mistral.test/v1/files', $url);
                $body = $options['body'];
                if (\is_callable($body)) {
                    $reader = $body;
                    $body = '';
                    while ('' !== $chunk = $reader(8192)) {
                        $body .= $chunk;
                    }
                }
                if (is_iterable($body)) {
                    $body = implode('', iterator_to_array($body));
                }
                $this->assertStringContainsString('page-1', $body);
                $this->assertStringContainsString('image_url', $body);

                return new JsonMockResponse(['id' => 'file-1']);
            },
            function ($method, $url, $options) {
                $this->assertSame('https://mistral.test/v1/batch/jobs', $url);
                $body = json_decode($options['body'], true);
                $this->assertSame('/v1/ocr', $body['endpoint']);
                $this->assertSame(2, $body['timeout_hours']);

                return new JsonMockResponse(['id' => 'job-1', 'endpoint' => '/v1/ocr']);
            },
        ]);
        $handle = Factory::createPlatform('key', $http, name: 'custom', baseUrl: 'https://mistral.test/')->invoke('mistral-ocr-latest', [
            'page-1' => new ImageUrl('https://example.org/page.jpg'),
        ], ['batch' => true, 'timeout_hours' => 2])->asJob();
        $this->assertSame(7200, $handle->getMaxDuration());
        $this->assertSame('custom', $handle->getProvider());
        $this->assertSame('/v1/ocr', $handle->get('endpoint'));
        $this->assertSame($handle->toArray(), JobHandle::fromString($handle->toString())->toArray());
    }

    public function testOcrStringResponseAndErrorArePreserved()
    {
        $http = new MockHttpClient([
            new JsonMockResponse(['status' => 'SUCCESS', 'output_file' => 'out', 'error_file' => 'err']),
            new MockResponse(json_encode(['custom_id' => 'p2', 'response' => ['status_code' => 200, 'body' => json_encode(['pages' => [['index' => 0, 'markdown' => 'Unchanged OCR']]])]])),
            new MockResponse(json_encode(['custom_id' => 'p1', 'response' => ['status_code' => 400, 'body' => json_encode(['message' => 'Cannot fetch image', 'code' => '3310'])]])),
        ]);
        $items = iterator_to_array((new JobClient($http, 'key'))->getResult(new JobHandle('job-1', ['kind' => JobClient::KIND, 'endpoint' => '/v1/ocr']))->getContent());
        $this->assertSame('p2', $items[0]->getId());
        $this->assertSame('Unchanged OCR', $items[0]->getResult()->getContent()->getMarkdown());
        $this->assertFalse($items[1]->isSuccess());
        $this->assertSame('Cannot fetch image', $items[1]->getError());
        $this->assertSame('3310', $items[1]->getRaw());
    }

    public function testBatchPreservesMaxOutputTokensNormalizationAfterRebase()
    {
        $http = new MockHttpClient([
            function ($method, $url, $options) {
                $body = $options['body'];
                if (\is_callable($body)) {
                    $reader = $body;
                    $body = '';
                    while ('' !== $chunk = $reader(8192)) {
                        $body .= $chunk;
                    }
                }
                if (is_iterable($body)) {
                    $body = implode('', iterator_to_array($body));
                }
                $this->assertStringContainsString('"max_tokens":50', $body);
                $this->assertStringNotContainsString('max_output_tokens', $body);

                return new JsonMockResponse(['id' => 'file-1']);
            },
            new JsonMockResponse(['id' => 'job-1']),
        ]);
        Factory::createPlatform('key', $http)->invoke('mistral-small-latest', [
            'a' => new MessageBag(Message::ofUser('Hello')),
        ], ['batch' => true, 'max_output_tokens' => 50])->asJob();
    }
}
