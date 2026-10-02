<?php

declare(strict_types=1);

class ProductsExporter
{
    private array $config;

    private ProductRepository $repository;

    private CsvWriter $csv;

    private JsonWriter $json;

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

        return [

            'name' => 'products',

            'count' => count($products),

            'data' => $products

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