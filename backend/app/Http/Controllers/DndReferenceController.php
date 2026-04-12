<?php

namespace App\Http\Controllers;

use App\Services\DndDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DndReferenceController extends Controller
{
    public function __construct(private DndDataService $dnd) {}

    public function classes(): JsonResponse
    {
        return response()->json($this->dnd->getAllClasses());
    }

    public function classDetail(string $class): JsonResponse
    {
        $data = $this->dnd->getClassData($class);

        if (!$data) {
            return response()->json(['error' => 'Class not found'], 404);
        }

        return response()->json($data);
    }

    public function spells(Request $request): JsonResponse
    {
        $class = $request->query('class');
        $maxLevel = (int) ($request->query('max_level', 9));

        if ($class) {
            return response()->json($this->dnd->getSpellsForClass($class, $maxLevel));
        }

        return response()->json($this->dnd->getAllSpells());
    }

    public function spell(string $slug): JsonResponse
    {
        $data = $this->dnd->getSpell($slug);

        if (!$data) {
            return response()->json(['error' => 'Spell not found'], 404);
        }

        return response()->json($data);
    }

    public function equipment(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if ($type) {
            return response()->json($this->dnd->getEquipmentByType($type));
        }

        return response()->json($this->dnd->getAllEquipment());
    }

    public function conditions(): JsonResponse
    {
        return response()->json($this->dnd->getAllConditions());
    }

    public function xpTable(): JsonResponse
    {
        return response()->json($this->dnd->getXpTable());
    }
}
