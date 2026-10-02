<?php

declare(strict_types=1);

class ProductsExporter
{
    private array $config;

    private ProductRepository $repository;

    private CsvWriter $csv;

    private JsonWriter $json;

    private MarkdownWriter $markdown;

    public function __construct(array $config)
    {
        $this->config = $config;

        $output = rtrim($config['output'], '/');

        $this->repository = new ProductRepository();

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
     * اجرای Export
     */
    public function export(): array
    {
        $products = $this->repository->all();

        $this->csv->write(
            'products.csv',
            $products
        );

        $this->json->write(
            'products.json',
            $products
        );

        foreach ($products as $product) {
            $id = $product['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'products',
                (string)$id,
                $product
            );
        }

        return [
            'name' => 'products',
            'count' => count($products),
            'data' => $products,
        ];
    }

    /**
     * فقط فایل CSV
     */
    public function exportCsv(): void
    {
        $this->csv->write(
            'products.csv',
            $this->repository->all()
        );
    }

    /**
     * فقط فایل JSON
     */
    public function exportJson(): void
    {
        $this->json->write(
            'products.json',
            $this->repository->all()
        );
    }

    /**
     * فقط Markdown
     */
    public function exportMarkdown(): void
    {
        $products = $this->repository->all();

        foreach ($products as $product) {
            $id = $product['basic']['id'] ?? uniqid();

            $this->markdown->write(
                'products',
                (string)$id,
                $product
            );
        }
    }

    /**
     * تعداد محصولات
     */
    public function count(): int
    {
        return $this->repository->count();
    }

    /**
     * دریافت Knowledge Object
     */
    public function knowledge(): array
    {
        return $this->repository->all();
    }
}
