<?php

declare(strict_types=1);

class CsvWriter
{
    private string $outputDirectory;

    public function __construct(string $outputDirectory)
    {
        $this->outputDirectory = rtrim(
            $outputDirectory,
            '/'
        );

        ai_make_directory(
            $this->outputDirectory
        );
    }

    public function write(
        string $filename,
        array $rows
    ): void {

        if (empty($rows)) {
            return;
        }

        $file = $this->outputDirectory . '/' . $filename;

        ai_make_directory(
            dirname($file)
        );

        $fp = fopen($file, 'w');

        if (!$fp) {
            throw new RuntimeException(
                'Cannot create file : ' . $file
            );
        }

        // UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");

        $headers = array_keys(
            ai_flatten($rows[0])
        );

        fputcsv($fp, $headers);

        foreach ($rows as $row) {

            $flat = ai_flatten($row);

            $line = [];

            foreach ($headers as $header) {

                $line[] = $flat[$header] ?? '';

            }

            fputcsv(
                $fp,
                $line
            );

        }

        fclose($fp);

    }

}