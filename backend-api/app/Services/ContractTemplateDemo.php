<?php

namespace App\Services;

class ContractTemplateDemo
{
    public const KEYS = ['ROOM_RENTAL_DEMO', 'DORM_BED_DEMO'];

    public function __construct(private ContractTemplateRenderer $renderer) {}

    public function definitions(): array
    {
        return require resource_path('demo/contracts.php');
    }

    public function content(string $type): string
    {
        $definitions = $this->definitions();
        abort_unless(isset($definitions[$type]), 404);

        return $this->renderer->prepare(file_get_contents(resource_path('views/'.$definitions[$type]['template'])));
    }

    public function document(string $type): array
    {
        $definitions = $this->definitions();
        abort_unless(isset($definitions[$type]), 404);
        $demo = $definitions[$type];

        return ['is_demo' => true, 'name' => $demo['name'], 'filename' => $demo['filename'], 'summary' => $demo['summary'],
            'source_snapshot' => $demo['snapshot'], 'rendered_html' => $this->renderer->render($this->content($type), $demo['snapshot'])];
    }
}
