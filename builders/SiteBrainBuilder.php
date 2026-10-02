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
        $knowledgeGraph = $this->graphBuilder->build($knowledge);
        $linkGraph = $knowledgeGraph['internal_link_graph'] ?? [];
        $analysis = $linkGraph['analysis'] ?? [];

        return [
            'version' => '3.1',
            'generated_at' => current_time('mysql'),
            'site' => $knowledge['site'],
            'statistics' => $knowledge['statistics'],
            'taxonomy' => $knowledge['taxonomy'],
            'content_clusters' => $knowledge['content_clusters'],
            'knowledge_graph' => $knowledgeGraph,
            'link_analysis' => [
                'summary' => $analysis['summary'] ?? [
                    'node_count' => 0,
                    'total_outgoing' => 0,
                    'orphan_count' => 0,
                    'weak_hub_count' => 0,
                    'dead_link_count' => 0,
                    'duplicate_anchor_pages' => 0,
                ],
                'orphans' => $analysis['orphans'] ?? [],
                'weak_hubs' => $analysis['weak_hubs'] ?? [],
                'dead_internal_links' => $analysis['dead_internal_links'] ?? [],
                'duplicate_anchors' => $analysis['duplicate_anchors'] ?? [],
                'links_by_category' => $analysis['links_by_category'] ?? [],
            ],
            'relationships' => $this->relationshipBuilder->build($knowledge),
            'knowledge' => [
                'products' => $knowledge['products'],
                'categories' => $knowledge['categories'],
                'posts' => $knowledge['posts'],
                'pages' => $knowledge['pages'],
                'media' => $knowledge['media'],
            ],
        ];
    }
}
