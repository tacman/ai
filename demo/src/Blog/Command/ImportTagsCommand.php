<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Blog\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Tac
 */
#[AsCommand('app:blog:import-tags', 'Persist a Jev blog evaluation without generating embeddings.')]
final readonly class ImportTagsCommand
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(#[Argument('JSON output from jev-blog-demo.php.')] string $file, SymfonyStyle $io): int
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException('The evaluation file must exist and be readable.');
        }
        $evaluation = json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        foreach (['article', 'state', 'source_metadata', 'probabilities', 'suggested_tags', 'model', 'questions', 'evaluated_at'] as $key) {
            if (!isset($evaluation[$key])) {
                throw new InvalidArgumentException(\sprintf('Missing evaluation field: %s.', $key));
            }
        }
        $this->save($evaluation);
        $io->success(\sprintf('Saved %s; no embeddings generated.', $evaluation['article']['title']));

        return 0;
    }

    /** @param array<string, mixed> $evaluation */
    public function save(array $evaluation): void
    {
        // Articles have a different lifetime from vector chunks. This table shares the
        // existing Postgres connection but does not require a vector or an AI call.
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS symfony_blog_article (
                url TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                source_metadata JSONB NOT NULL,
                source_categories JSONB NOT NULL,
                tags JSONB NOT NULL,
                tag_probabilities JSONB NOT NULL,
                evaluation JSONB NOT NULL,
                tagged_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $json = static fn (mixed $value): string => json_encode($value, \JSON_THROW_ON_ERROR);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO symfony_blog_article
                (url, title, body, source_metadata, source_categories, tags, tag_probabilities, evaluation, tagged_at)
            VALUES (:url, :title, :body, :metadata, :categories, :tags, :probabilities, :evaluation, :tagged_at)
            ON CONFLICT (url) DO UPDATE SET
                title = EXCLUDED.title, body = EXCLUDED.body,
                source_metadata = EXCLUDED.source_metadata, source_categories = EXCLUDED.source_categories,
                tags = EXCLUDED.tags, tag_probabilities = EXCLUDED.tag_probabilities,
                evaluation = EXCLUDED.evaluation, tagged_at = EXCLUDED.tagged_at
            SQL, [
            'url' => $evaluation['article']['url'],
            'title' => $evaluation['article']['title'],
            'body' => $evaluation['state']['body'],
            'metadata' => $json($evaluation['source_metadata']),
            'categories' => $json($evaluation['article']['existing_categories']),
            'tags' => $json($evaluation['suggested_tags']),
            'probabilities' => $json($evaluation['probabilities']),
            'evaluation' => $json($evaluation),
            'tagged_at' => $evaluation['evaluated_at'],
        ]);
    }
}
