<?php

declare(strict_types=1);

class KnowledgeGraphBuilder
{
    private NavigationBuilder $navigationBuilder;
    private InternalLinkGraphBuilder $linkGraphBuilder;
    private WooCommerceRelationshipBuilder $wooBuilder;

    public function __construct()
    {
        $this->navigationBuilder = new NavigationBuilder();
        $this->linkGraphBuilder = new InternalLinkGraphBuilder();
        $this->wooBuilder = new WooCommerceRelationshipBuilder();
    }

    public function build(array $knowledge): array
    {
        $navigation = $this->navigationBuilder->build();
        $menuObjectIds = $this->navigationBuilder->objectIdMap();
        $linkGraph = $this->linkGraphBuilder->build($knowledge, $menuObjectIds);

        return [
            'site' => [
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'description' => get_bloginfo('description'),
            ],
            'navigation' => $navigation,
            'internal_link_graph' => $linkGraph,
            'woocommerce' => $this->wooBuilder->build(),
            'entities' => [
                'products' => count($knowledge['products'] ?? []),
                'categories' => count($knowledge['categories'] ?? []),
                'posts' => count($knowledge['posts'] ?? []),
                'pages' => count($knowledge['pages'] ?? []),
                'media' => count($knowledge['media'] ?? []),
            ],
        ];
    }
}
