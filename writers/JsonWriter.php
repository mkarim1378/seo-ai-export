<?php

declare(strict_types=1);

class JsonWriter
{
    private string $outputDirectory;

    public function __construct(string $outputDirectory)
    {
        $this->outputDirectory = rtrim($outputDirectory, '/');

        ai_make_directory($this->outputDirectory);
    }

    /**
     * ذخیره یک فایل JSON
     */
    public function write(
        string $filename,
        array $data
    ): void {

        $file = $this->outputDirectory . '/' . $filename;

        ai_make_directory(dirname($file));

        file_put_contents(

            $file,

            json_encode(

                $data,

                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES

            )

        );

    }

    /**
     * ساخت فایل Manifest
     */
    public function manifest(array $sections): void
    {

        $manifest = [

            'generator' => 'AI Site Intelligence Engine',

            'version' => '2.0.0',

            'generated_at' => date('c'),

            'site' => [

                'name' => get_bloginfo('name'),

                'url' => home_url('/'),

                'language' => get_locale(),

            ],

            'sections' => []

        ];

        foreach ($sections as $name => $rows) {

            $manifest['sections'][] = [

                'name' => $name,

                'count' => is_array($rows)
                    ? count($rows)
                    : 0

            ];

        }

        $this->write(
            'manifest/manifest.json',
            $manifest
        );

    }

    /**
     * ساخت فایل Knowledge
     */
    public function knowledge(array $datasets): void
    {

        $knowledge = [

            'meta' => [

                'generator' => 'AI Site Intelligence Engine',

                'version' => '2.0.0',

                'generated_at' => date('c'),

                'site_name' => get_bloginfo('name'),

                'site_url' => home_url('/'),

                'language' => get_locale(),

            ],

            'datasets' => $datasets

        ];

        $this->write(

            'knowledge.json',

            $knowledge

        );

    }

}