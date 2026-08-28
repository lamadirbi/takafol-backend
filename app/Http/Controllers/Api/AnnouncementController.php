<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOptionalSanctumUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AnnouncementReaction;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    use ResolvesOptionalSanctumUser;

    public function __construct(private readonly WebPushService $webPush) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveOptionalSanctumUser($request);

        $query = Announcement::query()
            ->with([
                'adminUser:id,name,role,username,is_super,camp_id,email,national_id,created_at',
                'comments' => fn ($q) => $q
                    ->select('id', 'announcement_id', 'user_id', 'body', 'created_at', 'updated_at')
                    ->orderBy('id'),
                'comments.user:id,name,role',
            ])
            ->withCount([
                'comments',
                'reactions as reactions_like_count' => fn ($q) => $q->where('type', AnnouncementReaction::TYPE_LIKE),
                'reactions as reactions_interested_count' => fn ($q) => $q->where('type', AnnouncementReaction::TYPE_INTERESTED),
                'reactions as reactions_thanks_count' => fn ($q) => $q->where('type', AnnouncementReaction::TYPE_THANKS),
            ]);

        if ($user) {
            $query->with([
                'reactions' => fn ($q) => $q->where('user_id', $user->id),
            ]);
        }

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
        $items = $query->latest()->paginate($perPage);

        return AnnouncementResource::collection($items)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'published_at' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('announcements', 'public');
        }

        $announcement = Announcement::query()->create([
            'title' => $data['title'],
            'content' => $data['content'],
            'image_path' => $imagePath,
            'admin_user_id' => $request->user()->id,
            'published_at' => $data['published_at'] ?? now(),
        ]);
        $announcement->load([
            'adminUser:id,name,role,username,is_super,camp_id,email,national_id,created_at',
        ]);

        // إشعار Push لكل العائلات عند نشر خبر جديد.
        $title = 'خبر جديد من '.($request->user()->name ?? 'إدارة المخيم');
        $body = (string) $announcement->title;
        $camp = \Illuminate\Support\Facades\App::has('current_camp')
            ? \Illuminate\Support\Facades\App::get('current_camp')
            : $request->user()?->camp;
        $slug = is_object($camp) ? (string) ($camp->slug ?? '') : '';
        $url = $slug !== '' ? '/'.$slug.'/news#post-'.$announcement->id : '/news';
        $this->webPush->notifyAllFamilyHeadsAfterResponse($title, $body, $url, [
            'type' => 'announcement',
            'announcement_id' => $announcement->id,
        ]);

        return (new AnnouncementResource($announcement))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 403);
        abort_unless(
            (int) $announcement->admin_user_id === (int) $user->id || $user->isSuper() || $user->isPrimaryCampAdmin(),
            403,
            'يمكن لمن أنشأ المنشور تعديله.'
        );

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'required', 'string'],
            'published_at' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('title', $data)) {
            $announcement->title = $data['title'];
        }
        if (array_key_exists('content', $data)) {
            $announcement->content = $data['content'];
        }
        if (array_key_exists('published_at', $data) && $data['published_at']) {
            $announcement->published_at = $data['published_at'];
        }

        if ($request->boolean('remove_image') && $announcement->image_path) {
            Storage::disk('public')->delete($announcement->image_path);
            $announcement->image_path = null;
        }

        if ($request->hasFile('image')) {
            if ($announcement->image_path) {
                Storage::disk('public')->delete($announcement->image_path);
            }
            $announcement->image_path = $request->file('image')->store('announcements', 'public');
        }

        $announcement->save();
        $announcement->load([
            'adminUser:id,name,role,username,is_super,camp_id,email,national_id,created_at',
            'comments' => fn ($q) => $q
                ->select('id', 'announcement_id', 'user_id', 'body', 'created_at', 'updated_at')
                ->orderBy('id'),
            'comments.user:id,name,role',
            'reactions' => fn ($q) => $q->where('user_id', $user->id),
        ]);
        $announcement->loadCount([
            'comments',
            'reactions as reactions_like_count' => fn ($q) => $q->where('type', AnnouncementReaction::TYPE_LIKE),
            'reactions as reactions_interested_count' => fn ($q) => $q->where('type', AnnouncementReaction::TYPE_INTERESTED),
            'reactions as reactions_thanks_count' => fn ($q) => $q->where('type', AnnouncementReaction::TYPE_THANKS),
        ]);

        return (new AnnouncementResource($announcement))->response();
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $user = request()->user();
        abort_unless($user && $user->isAdmin(), 403);
        abort_unless(
            (int) $announcement->admin_user_id === (int) $user->id || $user->isSuper() || $user->isPrimaryCampAdmin(),
            403,
            'يمكن لمن أنشأ المنشور حذفه.'
        );

        if ($announcement->image_path) {
            Storage::disk('public')->delete($announcement->image_path);
        }

        $announcement->delete();

        return response()->json(null, 204);
    }
}
