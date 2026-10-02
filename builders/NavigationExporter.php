<?php

class NavigationExporter
{
    public function export(array $navigation)
    {
        $output = AI_OUTPUT_DIR . '/navigation.json';

        file_put_contents(

            $output,

            json_encode(

                $navigation,

                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES

            )

        );

        return $output;
    }
}