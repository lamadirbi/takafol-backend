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

        $reactionCounts = [
            'like' => $announcement->reactions()->where('type', AnnouncementReaction::TYPE_LIKE)->count(),
            'interested' => $announcement->reactions()->where('type', AnnouncementReaction::TYPE_INTERESTED)->count(),
            'thanks' => $announcement->reactions()->where('type', AnnouncementReaction::TYPE_THANKS)->count(),
        ];

        return response()->json([
            'active' => $active,
            'type' => $data['type'],
            'reaction_counts' => $reactionCounts,
        ]);
    }
}
