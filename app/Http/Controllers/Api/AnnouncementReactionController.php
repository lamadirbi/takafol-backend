<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementReactionController extends Controller
{
    public function toggle(Request $request, Announcement $announcement): JsonResponse
    {
        $data = $request->validate([
            'type' => [
                'required',
                'string',
                Rule::in([
                    AnnouncementReaction::TYPE_LIKE,
                    AnnouncementReaction::TYPE_INTERESTED,
                    AnnouncementReaction::TYPE_THANKS,
                ]),
            ],
        ]);

        $user = $request->user();

        $query = AnnouncementReaction::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->where('type', $data['type']);

        $existing = $query->first();

        if ($existing) {
            $existing->delete();
            $active = false;
        } else {
            AnnouncementReaction::query()->create([
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'type' => $data['type'],
            ]);
            $active = true;
        }

        $counts = $announcement->reactions()
            ->selectRaw('type, COUNT(*) as aggregate_count')
            ->groupBy('type')
            ->pluck('aggregate_count', 'type');

        $reactionCounts = [
            'like' => (int) ($counts[AnnouncementReaction::TYPE_LIKE] ?? 0),
            'interested' => (int) ($counts[AnnouncementReaction::TYPE_INTERESTED] ?? 0),
            'thanks' => (int) ($counts[AnnouncementReaction::TYPE_THANKS] ?? 0),
        ];

        return response()->json([
            'active' => $active,
            'type' => $data['type'],
            'reaction_counts' => $reactionCounts,
        ]);
    }

    public function index(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 403);
        abort_unless(
            (int) $announcement->admin_user_id === (int) $user->id || $user->isSuper() || $user->isPrimaryCampAdmin(),
            403,
            'يمكن لمن أنشأ المنشور عرض من تفاعل عليه.'
        );

        $rows = $announcement->reactions()
            ->with('user:id,name,role')
            ->orderBy('id')
            ->get();

        $grouped = [
            AnnouncementReaction::TYPE_LIKE => [],
            AnnouncementReaction::TYPE_INTERESTED => [],
            AnnouncementReaction::TYPE_THANKS => [],
        ];

        foreach ($rows as $row) {
            $type = (string) $row->type;
            if (! isset($grouped[$type])) {
                continue;
            }
            $grouped[$type][] = [
                'id' => $row->user_id,
                'name' => $row->user?->name ?: 'مستخدم',
            ];
        }

        return response()->json([
            'like' => $grouped[AnnouncementReaction::TYPE_LIKE],
            'interested' => $grouped[AnnouncementReaction::TYPE_INTERESTED],
            'thanks' => $grouped[AnnouncementReaction::TYPE_THANKS],
        ]);
    }
}
