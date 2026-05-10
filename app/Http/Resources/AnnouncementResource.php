<?php

namespace App\Http\Resources;

use App\Models\AnnouncementReaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'image_url' => $this->image_path
                ? asset('storage/'.$this->image_path)
                : null,
            'published_at' => $this->published_at,
            'admin_user' => new UserResource($this->whenLoaded('adminUser')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'created_at' => $this->created_at,
            'reaction_counts' => [
                'like' => (int) ($this->reactions_like_count ?? 0),
                'interested' => (int) ($this->reactions_interested_count ?? 0),
                'thanks' => (int) ($this->reactions_thanks_count ?? 0),
            ],
            'my_reactions' => $this->when($request->user() && $this->relationLoaded('reactions'), function () {
                return [
                    'like' => $this->reactions->contains('type', AnnouncementReaction::TYPE_LIKE),
                    'interested' => $this->reactions->contains('type', AnnouncementReaction::TYPE_INTERESTED),
                    'thanks' => $this->reactions->contains('type', AnnouncementReaction::TYPE_THANKS),
                ];
            }),
        ];
    }
}
