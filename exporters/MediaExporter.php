<?php

declare(strict_types=1);

class MediaExporter
{
    private array $config;

    private MediaRepository $repository;

    private CsvWriter $csv;

    private JsonWriter $json;

    private MarkdownWriter $markdown;

    public function __construct(array $config)
    {
        $this->config = $config;

        $output = rtrim($config['output'], '/');

        $this->repository = new MediaRepository();

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
     * Export همه فایل‌های رسانه
     */
    public function export(): array
    {
        $media = $this->repository->all();

        $this->csv->write(
            'media.csv',
            $media
        );

        $this->json->write(
            'media.json',
            $media
        );

        foreach ($media as $item) {

            $id = $item['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'media',
                (string)$id,
                $item
            );

        }

        return [

            'name' => 'media',

            'count' => count($media),

            'data' => $media

        ];
    }

    /**
     * فقط CSV
     */
    public function exportCsv(): void
    {
        $this->csv->write(
            'media.csv',
            $this->repository->all()
        );
    }

    /**
     * فقط JSON
     */
    public function exportJson(): void
    {
        $this->json->write(
            'media.json',
            $this->repository->all()
        );
    }

    /**
     * فقط Markdown
     */
    public function exportMarkdown(): void
    {
        $media = $this->repository->all();

        foreach ($media as $item) {

            $id = $item['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'media',
                (string)$id,
                $item
            );

        }
    }

    /**
     * تعداد فایل‌های رسانه
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