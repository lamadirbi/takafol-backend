<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Announcement;
use App\Models\Comment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request, Announcement $announcement): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = Comment::query()->create([
            'announcement_id' => $announcement->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);
        $comment->load('user');

        return (new CommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Announcement $announcement, Comment $comment): JsonResponse
    {
        $this->assertCommentOnPost($announcement, $comment);
        $user = $request->user();
        abort_unless($user && (int) $comment->user_id === (int) $user->id, 403, 'يمكنك تعديل تعليقك فقط.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update(['body' => $data['body']]);
        $comment->load('user');

        return (new CommentResource($comment))->response();
    }

    public function destroy(Request $request, Announcement $announcement, Comment $comment): JsonResponse
    {
        $this->assertCommentOnPost($announcement, $comment);
        $user = $request->user();
        $isOwner = $user && (int) $comment->user_id === (int) $user->id;
        $isPostAuthor = $user && (int) $announcement->admin_user_id === (int) $user->id;
        abort_unless($isOwner || ($user && $user->isAdmin() && $isPostAuthor), 403, 'يمكنك حذف تعليقك فقط.');

        $comment->delete();

        return response()->json(null, 204);
    }

    private function assertCommentOnPost(Announcement $announcement, Comment $comment): void
    {
        abort_unless((int) $comment->announcement_id === (int) $announcement->id, 404);
    }
}
