<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DndDataService
{
    private function loadData(string $file): array
    {
        return Cache::rememberForever("dnd5e.{$file}", function () use ($file) {
            $path = storage_path("app/dnd5e/{$file}.json");
            if (!file_exists($path)) {
                return [];
            }
            return json_decode(file_get_contents($path), true) ?? [];
        });
    }

    public function getClassData(string $class): ?array
    {
        $classes = $this->loadData('classes');
        return $classes[strtolower($class)] ?? null;
    }

    public function getAllClasses(): array
    {
        return $this->loadData('classes');
    }

    public function getSpell(string $slug): ?array
    {
        $spells = $this->loadData('spells');
        return $spells[$slug] ?? null;
    }

    public function getSpellsForClass(string $class, int $maxLevel = 9): array
    {
        $spells = $this->loadData('spells');
        $class = strtolower($class);

        return array_filter($spells, function ($spell) use ($class, $maxLevel) {
            return in_array($class, $spell['classes'] ?? []) && ($spell['level'] ?? 0) <= $maxLevel;
        });
    }

    public function getAllSpells(): array
    {
        return $this->loadData('spells');
    }

    public function getXpTable(): array
    {
        return $this->loadData('xp_table');
    }

    public function getXpForLevel(int $level): int
    {
        $table = $this->getXpTable();
        return $table[(string) $level]['xp'] ?? 0;
    }

    public function getProficiencyBonus(int $level): int
    {
        $table = $this->getXpTable();
        return $table[(string) $level]['proficiency'] ?? 2;
    }

    public function getLevelForXp(int $xp): int
    {
        $table = $this->getXpTable();
        $level = 1;
        foreach ($table as $lvl => $data) {
            if ($xp >= $data['xp']) {
                $level = (int) $lvl;
            }
        }
        return $level;
    }

    public function getEquipment(string $slug): ?array
    {
        $equipment = $this->loadData('equipment');
        return $equipment[$slug] ?? null;
    }

    public function getAllEquipment(): array
    {
        return $this->loadData('equipment');
    }

    public function getEquipmentByType(string $type): array
    {
        $equipment = $this->loadData('equipment');
        return array_filter($equipment, fn($item) => ($item['type'] ?? '') === $type);
    }

    public function getCondition(string $slug): ?array
    {
        $conditions = $this->loadData('conditions');
        return $conditions[$slug] ?? null;
    }

    public function getAllConditions(): array
    {
        return $this->loadData('conditions');
    }
}
