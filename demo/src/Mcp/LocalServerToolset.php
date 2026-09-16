<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Wire\McpHeader;
use Mcp\Server\Builder;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

/**
 * The tools of an MCP server this application itself exposes, called in-process.
 *
 * Lets a chat agent use exactly the #[McpTool] services external agents see, without a
 * transport: no child process, no HTTP round trip to itself. Requests go through the
 * server's own stateless protocol, so schema validation, result formatting and MCP App
 * handlers behave as they do for a remote client.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
final class LocalServerToolset implements ToolsetInterface
{
    private ?StatelessProtocol $protocol = null;
    private int $id = 0;

    public function __construct(
        private readonly string $name,
        private readonly Builder $builder,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTools(): array
    {
        $tools = [];
        $cursor = null;
        do {
            $page = ListToolsResult::fromArray($this->request('tools/list', null === $cursor ? [] : ['cursor' => $cursor]));
            array_push($tools, ...$page->tools);
            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        return $tools;
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        return CallToolResult::fromArray($this->request('tools/call', ['name' => $name, 'arguments' => (object) $arguments]));
    }

    /**
     * The headers an HTTP client would send, since the stateless protocol checks them against the body.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    private function headers(string $method, array $params): array
    {
        $headers = [
            McpHeader::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            McpHeader::METHOD => $method,
        ];
        if (isset($params['name'])) {
            $headers[McpHeader::NAME] = $params['name'];
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $params): array
    {
        $this->protocol ??= $this->builder->buildStateless();

        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ];
        $result = $this->protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => ++$this->id,
            'method' => $method,
            'params' => $params,
        ], \JSON_THROW_ON_ERROR), $this->headers($method, $params));

        $message = json_decode($result->toJson(), true, flags: \JSON_THROW_ON_ERROR);
        if (isset($message['error'])) {
            throw new \RuntimeException(\sprintf('MCP server "%s" answered %s with error %d: %s', $this->name, $method, $message['error']['code'], $message['error']['message']));
        }

        return $message['result'];
    }
}
