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
            'version' => '3.2',
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
            'entity_index' => [
                'note' => 'Light index only. Full entity bodies are in knowledge.json.',
                'products' => $this->indexEntities($knowledge['products'] ?? [], 'product'),
                'categories' => $this->indexEntities($knowledge['categories'] ?? [], 'category'),
                'posts' => $this->indexEntities($knowledge['posts'] ?? [], 'post'),
                'pages' => $this->indexEntities($knowledge['pages'] ?? [], 'page'),
                'media' => $this->indexEntities($knowledge['media'] ?? [], 'media'),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $entities
     * @return list<array<string,mixed>>
     */
    private function indexEntities(array $entities, string $type): array
    {
        $index = [];

        foreach ($entities as $entity) {
            $index[] = [
                'id' => (int)($entity['basic']['id'] ?? 0),
                'type' => $type,
                'title' => (string)($entity['basic']['title'] ?? $entity['basic']['name'] ?? ''),
                'url' => (string)($entity['basic']['url'] ?? ''),
                'status' => (string)($entity['basic']['status'] ?? 'publish'),
                'word_count' => (int)($entity['content']['word_count'] ?? 0),
                'seo_title' => (string)($entity['seo']['title'] ?? ''),
            ];
        }

        return $index;
    }
}
