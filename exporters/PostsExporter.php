<?php

declare(strict_types=1);

class PostsExporter
{
    private array $config;

    private PostRepository $repository;

    private CsvWriter $csv;

    private JsonWriter $json;

    private MarkdownWriter $markdown;

    public function __construct(array $config)
    {
        $this->config = $config;

        $output = rtrim($config['output'], '/');

        $this->repository = new PostRepository();

        $this->csv = new CsvWriter(
            $output . '/csv'
        );

        $this->json = new JsonWriter(
            $output . '/json'
        );

        $this->markdown = new MarkdownWriter(
            $output . '/markdown'
        );
    }

    /**
     * Export همه نوشته‌ها
     */
    public function export(): array
    {
        $posts = $this->repository->all();

        $this->csv->write(
            'posts.csv',
            $posts
        );

        $this->json->write(
            'posts.json',
            $posts
        );

        foreach ($posts as $post) {

            $id = $post['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'posts',
                (string)$id,
                $post
            );

        }

        return [

            'name' => 'posts',

            'count' => count($posts),

            'data' => $posts

        ];
    }

    /**
     * فقط CSV
     */
    public function exportCsv(): void
    {
        $this->csv->write(
            'posts.csv',
            $this->repository->all()
        );
    }

    /**
     * فقط JSON
     */
    public function exportJson(): void
    {
        $this->json->write(
            'posts.json',
            $this->repository->all()
        );
    }

    /**
     * فقط Markdown
     */
    public function exportMarkdown(): void
    {
        $posts = $this->repository->all();

        foreach ($posts as $post) {

            $id = $post['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'posts',
                (string)$id,
                $post
            );

        }
    }

    /**
     * تعداد نوشته‌ها
     */
    public function count(): int
    {
        return $this->repository->count();
    }

    /**
     * Knowledge Object
     */
    public function knowledge(): array
    {
        return $this->repository->all();
    }
}