<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\AnnouncementNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $data = $this->validated($request, $announcement);
        $announcement->update($data);

        return response()->json($announcement->fresh());
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    /**
     * @param  Announcement|null  $existing  The announcement being edited (null on create).
     */
    private function validated(Request $request, ?Announcement $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'body' => [$required, 'string'],
            'category' => ['sometimes', Rule::in(['maintenance', 'update', 'policy', 'holiday', 'general'])],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // The form sends times with their UTC offset; store them in the app
        // timezone so scheduling and the display window compare real moments.
        foreach (['starts_at', 'expires_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] ? Carbon::parse($data[$field])->setTimezone(config('app.timezone')) : null;
            }
        }

        $this->guardSchedule($data, $existing);

        return $data;
    }

    /**
     * An announcement's dates must make sense: the start can't be in the past
     * (blank means publish now), the end must be in the future, and the end
     * must come after the start. Without this, an announcement set to run
     * "Sep 13 until Jul 12" was accepted and its notification went out at once.
     *
     * On edit, only a date that actually changed is held to the "not in the
     * past" rules, so fixing a typo in an announcement that already started or
     * expired still saves. A few minutes of grace covers time spent on the form.
     */
    private function guardSchedule(array $data, ?Announcement $existing): void
    {
        $start = array_key_exists('starts_at', $data) ? $data['starts_at'] : $existing?->starts_at;
        $end = array_key_exists('expires_at', $data) ? $data['expires_at'] : $existing?->expires_at;

        $changed = function (string $field) use ($data, $existing): bool {
            if (! array_key_exists($field, $data)) {
                return false;
            }
            $old = $existing?->{$field};
            $new = $data[$field];

            return ! ($old === null && $new === null)
                && ! ($old && $new && $old->copy()->startOfMinute()->equalTo($new->copy()->startOfMinute()));
        };

        $errors = [];

        foreach (['starts_at' => $start, 'expires_at' => $end] as $field => $date) {
            if ($date && $changed($field) && $date->year > 2100) {
                $errors[$field] = "The year can't be after 2100.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if ($start && $changed('starts_at') && $start->lt(now()->subMinutes(5))) {
            $errors['starts_at'] = "The start date can't be in the past. Leave it blank to publish right away.";
        }

        if ($end && $changed('expires_at') && $end->lte(now())) {
            $errors['expires_at'] = 'The end date must be in the future.';
        } elseif ($start && $end && $end->lte($start)) {
            $errors['expires_at'] = 'The end date must be after the start date.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
