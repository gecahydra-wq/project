<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\AnnouncementNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index()
    {
        return response()->json(Announcement::with('creator')->latest()->get());
    }

    /**
     * Feeds the site-wide announcement bar on every page -- the public
     * storefront (guests included) and all four dashboards -- so this route is
     * public. Only ever the currently-active window (see
     * Announcement::scopeActive), regardless of whether the scheduled
     * notification command has run yet, and only the fields the bar displays:
     * who created it and when it was notified stay internal.
     */
    public function active()
    {
        return response()->json(
            Announcement::active()
                ->latest('starts_at')
                ->latest('id')
                ->get(['id', 'title', 'body', 'category', 'starts_at', 'expires_at', 'updated_at'])
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $announcement = Announcement::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        // Fire immediately when there's no future start date; otherwise the
        // scheduled command (see App\Console\Commands\
        // PublishScheduledAnnouncements) picks it up once starts_at arrives.
        if (! $announcement->starts_at || $announcement->starts_at->lte(now())) {
            AnnouncementNotifier::notifyAudience($announcement);
        }

        return response()->json($announcement->fresh(), 201);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $this->validated($request, partial: true);
        $announcement->update($data);

        return response()->json($announcement->fresh());
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'body' => [$required, 'string'],
            'category' => ['sometimes', Rule::in(['maintenance', 'update', 'policy', 'holiday', 'general'])],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);
    }
}
