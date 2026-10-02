<?php

declare(strict_types=1);

class SiteBrainBuilder
{
    private KnowledgeGraphBuilder $graphBuilder;

    private RelationshipBuilder $relationshipBuilder;

    private SemanticEnricher $semantic;

    public function __construct()
    {
        $this->graphBuilder = new KnowledgeGraphBuilder();

        $this->relationshipBuilder = new RelationshipBuilder();

        $this->semantic = new SemanticEnricher();
    }

    public function build(array $knowledge): array
    {
        $knowledge = $this->semantic->enrich($knowledge);

        return [

            'version' => '3.0',

            'generated_at' => current_time('mysql'),

            'site' => $knowledge['site'],

            'statistics' => $knowledge['statistics'],

            'taxonomy' => $knowledge['taxonomy'],

            'content_clusters' => $knowledge['content_clusters'],

            'knowledge_graph' => $this->graphBuilder->build($knowledge),

            'relationships' => $this->relationshipBuilder->build($knowledge),

            'knowledge' => [

                'products' => $knowledge['products'],

                'categories' => $knowledge['categories'],

                'posts' => $knowledge['posts'],

                'pages' => $knowledge['pages'],

                'media' => $knowledge['media']

            ]

        ];
    }
}