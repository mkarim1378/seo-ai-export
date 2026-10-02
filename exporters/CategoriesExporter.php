<?php

declare(strict_types=1);

class CategoriesExporter
{
    private array $config;

    private CategoryRepository $repository;

    private CsvWriter $csv;

    private JsonWriter $json;

    private MarkdownWriter $markdown;

    public function __construct(array $config)
    {
        $this->config = $config;

        $output = rtrim($config['output'], '/');

        $this->repository = new CategoryRepository();

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
     * Export همه دسته‌بندی‌ها
     */
    public function export(): array
    {
        $categories = $this->repository->all();

        $this->csv->write(
            'categories.csv',
            $categories
        );

        $this->json->write(
            'categories.json',
            $categories
        );

        foreach ($categories as $category) {

            $id = $category['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'categories',
                (string)$id,
                $category
            );

        }

        return [

            'name' => 'categories',

            'count' => count($categories),

            'data' => $categories

        ];
    }

    /**
     * فقط CSV
     */
    public function exportCsv(): void
    {
        $this->csv->write(
            'categories.csv',
            $this->repository->all()
        );
    }

    /**
     * فقط JSON
     */
    public function exportJson(): void
    {
        $this->json->write(
            'categories.json',
            $this->repository->all()
        );
    }

    /**
     * فقط Markdown
     */
    public function exportMarkdown(): void
    {
        $categories = $this->repository->all();

        foreach ($categories as $category) {

            $id = $category['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'categories',
                (string)$id,
                $category
            );

        }
    }

    /**
     * تعداد دسته‌بندی‌ها
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