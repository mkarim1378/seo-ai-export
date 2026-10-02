<?php

declare(strict_types=1);

class InternalLinkGraphBuilder
{
    public function build(): array
    {
        $graph = [];

        $posts = get_posts([

            'post_type' => 'any',

            'post_status' => 'publish',

            'posts_per_page' => -1

        ]);

        foreach ($posts as $post) {

            $content = (string)$post->post_content;

            preg_match_all(
                '/href=["\']([^"\']+)["\']/i',
                $content,
                $matches
            );

            $links = [];

            foreach ($matches[1] as $url) {

                $url = strtok($url, '#');

                if (!$url) {
                    continue;
                }

                if (strpos($url, home_url()) !== 0) {
                    continue;
                }

                $targetId = url_to_postid($url);

                $links[] = [

                    'target_post_id' => $targetId,

                    'target_url' => $url

                ];
            }

            $graph[] = [

                'post_id' => (int)$post->ID,

                'post_type' => $post->post_type,

                'title' => html_entity_decode($post->post_title),

                'url' => get_permalink($post),

                'outgoing_links' => $links,

                'outgoing_count' => count($links)

            ];
        }

        return $graph;
    }
}