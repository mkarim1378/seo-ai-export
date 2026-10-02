<?php

declare(strict_types=1);

class PagesExporter
{
    private array $config;

    private PageRepository $repository;

    private CsvWriter $csv;

    private JsonWriter $json;

    private MarkdownWriter $markdown;

    public function __construct(array $config)
    {
        $this->config = $config;

        $output = rtrim($config['output'], '/');

        $this->repository = new PageRepository();

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
     * Export همه صفحات
     */
    public function export(): array
    {
        $pages = $this->repository->all();

        $this->csv->write(
            'pages.csv',
            $pages
        );

        $this->json->write(
            'pages.json',
            $pages
        );

        foreach ($pages as $page) {

            $id = $page['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'pages',
                (string)$id,
                $page
            );

        }

        return [

            'name' => 'pages',

            'count' => count($pages),

            'data' => $pages

        ];
    }

    /**
     * فقط CSV
     */
    public function exportCsv(): void
    {
        $this->csv->write(
            'pages.csv',
            $this->repository->all()
        );
    }

    /**
     * فقط JSON
     */
    public function exportJson(): void
    {
        $this->json->write(
            'pages.json',
            $this->repository->all()
        );
    }

    /**
     * فقط Markdown
     */
    public function exportMarkdown(): void
    {
        $pages = $this->repository->all();

        foreach ($pages as $page) {

            $id = $page['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'pages',
                (string)$id,
                $page
            );

        }
    }

    /**
     * تعداد صفحات
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