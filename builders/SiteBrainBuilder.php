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

        $clusters = $this->semantic->enrichClusters(
            $knowledge['content_clusters'] ?? [],
            $knowledge,
            $linkGraph
        );

        $inboundMap = [];
        foreach ($linkGraph['nodes'] ?? [] as $node) {
            $inboundMap[($node['entity_type'] ?? '') . ':' . ($node['id'] ?? 0)] = (int)($node['incoming_count'] ?? 0);
        }

        return [
            'version' => '4.0',
            'generated_at' => current_time('mysql'),
            'site' => $knowledge['site'],
            'site_profile' => $knowledge['site_profile'] ?? [],
            'statistics' => $knowledge['statistics'],
            'taxonomy' => $knowledge['taxonomy'],
            'content_clusters' => $clusters,
            'knowledge_graph' => $knowledgeGraph,
            'link_analysis' => [
                'summary' => $analysis['summary'] ?? [
                    'node_count' => 0,
                    'total_outgoing' => 0,
                    'orphan_count' => 0,
                    'weak_hub_count' => 0,
                    'dead_link_count' => 0,
                    'duplicate_anchor_pages' => 0,
                    'link_opportunity_count' => 0,
                ],
                'orphans' => $analysis['orphans'] ?? [],
                'weak_hubs' => $analysis['weak_hubs'] ?? [],
                'dead_internal_links' => $analysis['dead_internal_links'] ?? [],
                'duplicate_anchors' => $analysis['duplicate_anchors'] ?? [],
                'links_by_category' => $analysis['links_by_category'] ?? [],
                'link_opportunities' => $analysis['link_opportunities'] ?? [],
            ],
            'relationships' => $this->relationshipBuilder->build($knowledge),
            'entity_index' => [
                'note' => 'Light index only. Full entity bodies are in knowledge.json.',
                'products' => $this->indexEntities($knowledge['products'] ?? [], 'product', $inboundMap),
                'categories' => $this->indexEntities($knowledge['categories'] ?? [], 'category', $inboundMap),
                'posts' => $this->indexEntities($knowledge['posts'] ?? [], 'post', $inboundMap),
                'pages' => $this->indexEntities($knowledge['pages'] ?? [], 'page', $inboundMap),
                'media' => $this->indexEntities($knowledge['media'] ?? [], 'media', $inboundMap),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $entities
     * @param array<string,int> $inboundMap
     * @return list<array<string,mixed>>
     */
    private function indexEntities(array $entities, string $type, array $inboundMap = []): array
    {
        $index = [];

        foreach ($entities as $entity) {
            $id = (int)($entity['basic']['id'] ?? 0);
            $index[] = [
                'id' => $id,
                'type' => $type,
                'title' => (string)($entity['basic']['title'] ?? $entity['basic']['name'] ?? ''),
                'url' => (string)($entity['basic']['url'] ?? ''),
                'status' => (string)($entity['basic']['status'] ?? 'publish'),
                'word_count' => (int)($entity['content']['word_count'] ?? 0),
                'seo_title' => (string)($entity['seo']['title'] ?? ''),
                'focus_keyword' => (string)($entity['seo']['focus_keyword'] ?? ''),
                'is_cornerstone' => !empty($entity['seo']['is_cornerstone']),
                'inbound_links' => $inboundMap[$type . ':' . $id] ?? 0,
                'total_sales' => (int)($entity['basic']['total_sales'] ?? 0),
            ];
        }

        return $index;
    }
}
