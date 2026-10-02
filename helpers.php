<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Text
|--------------------------------------------------------------------------
*/

if (!function_exists('ai_clean_text')) {

    function ai_clean_text(?string $text): string
    {
        if (!$text) {
            return '';
        }

        $text = wp_strip_all_tags($text);

        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

}

/*
|--------------------------------------------------------------------------
| Array
|--------------------------------------------------------------------------
*/

if (!function_exists('ai_is_assoc')) {

    function ai_is_assoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(
            0,
            count($array) - 1
        );
    }

}

if (!function_exists('ai_flatten')) {

    function ai_flatten(
        array $array,
        string $prefix = ''
    ): array {

        $result = [];

        foreach ($array as $key => $value) {

            $newKey = $prefix === ''
                ? (string)$key
                : $prefix . '.' . $key;

            if (is_array($value)) {

                if (ai_is_assoc($value)) {

                    $result += ai_flatten(
                        $value,
                        $newKey
                    );

                } else {

                    $result[$newKey] = implode(
                        ' | ',
                        array_map(function ($item) {

                            if (is_array($item)) {

                                return json_encode(
                                    $item,
                                    JSON_UNESCAPED_UNICODE
                                );

                            }

                            if (is_bool($item)) {
                                return $item ? 'true' : 'false';
                            }

                            return (string)$item;

                        }, $value)
                    );

                }

            } else {

                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }

                $result[$newKey] = $value;

            }

        }

        return $result;

    }

}

/*
|--------------------------------------------------------------------------
| Filesystem
|--------------------------------------------------------------------------
*/

if (!function_exists('ai_make_directory')) {

    function ai_make_directory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir(
                $path,
                0777,
                true
            );
        }
    }

}

if (!function_exists('ai_write_json')) {

    function ai_write_json(
        string $filename,
        array $data
    ): void {

        ai_make_directory(
            dirname($filename)
        );

        file_put_contents(

            $filename,

            json_encode(

                $data,

                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES

            )

        );

    }

}

/*
|--------------------------------------------------------------------------
| Media
|--------------------------------------------------------------------------
*/

if (!function_exists('ai_attachment')) {

    function ai_attachment(int $id): array
    {
        if (!$id) {

            return [];

        }

        $file = get_attached_file($id);
        $metadata = wp_get_attachment_metadata($id) ?: [];
        $filename = $file ? basename($file) : '';

        return [
            'id' => $id,
            'url' => wp_get_attachment_url($id),
            'title' => get_the_title($id),
            'alt' => get_post_meta(
                $id,
                '_wp_attachment_image_alt',
                true
            ),
            'caption' => wp_get_attachment_caption($id),
            'mime' => get_post_mime_type($id),
            'width' => isset($metadata['width']) ? (int)$metadata['width'] : null,
            'height' => isset($metadata['height']) ? (int)$metadata['height'] : null,
            'filename' => $filename,
        ];

    }

}