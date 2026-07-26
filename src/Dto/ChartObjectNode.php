<?php

namespace App\Dto;

class ChartObjectNode
{
    public string $type;
    public string $key;
    public string $label;
    public ?string $categoryKey;
    public float $x;
    public float $y;
    public float $rotation = 0;
    public ?int $seatCount = null;
    public ?string $shapeType = null;
    public bool $selectable = true;
    /** @var ChartObjectNode[] */
    public array $children = [];

    public static function fromArray(array $data): self
    {
        $node = new self();
        $node->type = $data['type'] ?? '';
        $node->key = $data['key'] ?? '';
        $node->label = $data['label'] ?? '';
        $node->categoryKey = $data['categoryKey'] ?? $data['category'] ?? null;
        $node->x = $data['x'] ?? 0;
        $node->y = $data['y'] ?? 0;
        $node->rotation = $data['rotation'] ?? 0;
        $node->seatCount = $data['seatCount'] ?? null;
        $node->shapeType = $data['shapeType'] ?? null;
        $node->selectable = $data['selectable'] ?? true;

        if (isset($data['children']) && is_array($data['children'])) {
            $node->children = array_map(fn($child) => self::fromArray($child), $data['children']);
        }

        return $node;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label,
            'categoryKey' => $this->categoryKey,
            'x' => $this->x,
            'y' => $this->y,
            'rotation' => $this->rotation,
            'seatCount' => $this->seatCount,
            'shapeType' => $this->shapeType,
            'selectable' => $this->selectable,
            'children' => array_map(fn($child) => $child->toArray(), $this->children),
        ];
    }
}

