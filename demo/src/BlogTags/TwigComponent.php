<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\BlogTags;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * @author Tac
 */
#[AsTwigComponent('blog_tags')]
final readonly class TwigComponent
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{url: string, title: string, snippet: string, source_categories: list<string>, probabilities: array<string, float>}>
     */
    public function getArticles(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative("SELECT url, title, source_metadata->>'description' AS snippet, source_categories, tag_probabilities FROM symfony_blog_article ORDER BY tagged_at DESC, url");
        } catch (TableNotFoundException) {
            return [];
        }

        return array_map(static function (array $row): array {
            $probabilities = json_decode($row['tag_probabilities'], true, flags: \JSON_THROW_ON_ERROR);
            arsort($probabilities);

            return [
                'url' => $row['url'],
                'title' => $row['title'],
                'snippet' => html_entity_decode(strip_tags($row['snippet']), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                'source_categories' => json_decode($row['source_categories'], true, flags: \JSON_THROW_ON_ERROR),
                'probabilities' => $probabilities,
            ];
        }, $rows);
    }
}
