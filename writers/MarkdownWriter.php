<?php

declare(strict_types=1);

class MarkdownWriter
{
    private string $outputDirectory;

    public function __construct(string $outputDirectory)
    {
        $this->outputDirectory = rtrim($outputDirectory, '/');

        ai_make_directory($this->outputDirectory);
    }

    public function write(string $directory, string $filename, array $data): void
    {
        $dir = $this->outputDirectory . '/' . trim($directory, '/');

        ai_make_directory($dir);

        $file = $dir . '/' . $filename . '.md';

        file_put_contents(
            $file,
            $this->render($data)
        );
    }

    private function render(array $data): string
    {
        $markdown = '';

        foreach ($data as $key => $value) {

            $title = ucfirst(str_replace('_', ' ', (string)$key));

            $markdown .= "## {$title}\n\n";

            $markdown .= $this->renderValue($value);

            $markdown .= "\n\n";

        }

        return trim($markdown);
    }

    private function renderValue($value, int $level = 0): string
    {
        if (is_array($value)) {

            if (empty($value)) {
                return '';
            }

            $assoc = array_keys($value) !== range(0, count($value) - 1);

            $text = '';

            foreach ($value as $key => $item) {

                $indent = str_repeat('  ', $level);

                if ($assoc) {

                    $text .= $indent . "- **{$key}**: ";

                    if (is_array($item) || is_object($item)) {

                        $text .= "\n";

                        $text .= $this->renderValue(
                            $this->normalize($item),
                            $level + 1
                        );

                    } else {

                        $text .= $this->scalar($item) . "\n";

                    }

                } else {

                    if (is_array($item) || is_object($item)) {

                        $text .= $indent . "-\n";

                        $text .= $this->renderValue(
                            $this->normalize($item),
                            $level + 1
                        );

                    } else {

                        $text .= $indent . "- " . $this->scalar($item) . "\n";

                    }

                }

            }

            return $text;
        }

        if (is_object($value)) {

            return $this->renderValue(
                $this->normalize($value),
                $level
            );
        }

        return $this->scalar($value);
    }

    private function normalize($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof JsonSerializable) {
            return (array)$value->jsonSerialize();
        }

        return json_decode(
            json_encode($value),
            true
        ) ?? [];
    }

    private function scalar($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_object($value) || is_array($value)) {

            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_PRETTY_PRINT
            );
        }

        return '';
    }
}