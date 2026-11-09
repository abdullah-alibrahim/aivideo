<?php

namespace App\Http\Controllers;

use App\Models\Reel;
use App\Models\ReelCollection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request): View
    {
        $lang = in_array($request->query('lang'), ['ar', 'en'], true) ? $request->query('lang') : (session('app_locale', 'ar'));
        app()->setLocale($lang);
        session(['app_locale' => $lang]);

        $total = Reel::count();
        $completed = Reel::where('status', 'completed')->count();
        $failed = Reel::where('status', 'failed')->count();
        $cancelled = Reel::where('status', 'cancelled')->count();
        $active = Reel::whereIn('status', ['queued', 'processing', 'cancellation_requested'])->count();
        $completedWithDuration = Reel::where('status', 'completed')->whereNotNull('duration');
        $qualityReports = Reel::where('status', 'completed')->whereNotNull('quality_report')->get(['quality_report']);

        $days = collect(range(13, 0))->map(function (int $daysAgo): array {
            $date = now()->startOfDay()->subDays($daysAgo);

            return [
                'label' => $date->format('m-d'),
                'count' => Reel::whereDate('created_at', $date)->count(),
                'completed' => Reel::whereDate('created_at', $date)->where('status', 'completed')->count(),
            ];
        })->values()->all();

        $group = static fn (string $column): array => Reel::query()
            ->selectRaw($column.', COUNT(*) as total')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['label' => (string) $row->{$column}, 'total' => (int) $row->total])
            ->values()
            ->all();

        $qualityReady = $qualityReports->filter(fn ($row): bool => data_get($row->quality_report, 'status') === 'ready')->count();
        $qualityReview = $qualityReports->count() - $qualityReady;

        return view('analytics.dashboard', [
            'lang' => $lang,
            'isAr' => $lang === 'ar',
            'metrics' => [
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'cancelled' => $cancelled,
                'active' => $active,
                'completion_rate' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
                'avg_duration' => round((float) ($completedWithDuration->avg('duration') ?? 0), 1),
                'quality_ready' => $qualityReady,
                'quality_review' => $qualityReview,
            ],
            'daily' => $days,
            'languages' => $group('language'),
            'niches' => $group('niche'),
            'templates' => $group('template'),
            'recent' => Reel::query()->latest()->limit(10)->get(['id', 'title', 'topic', 'status', 'language', 'duration', 'created_at']),
            'collections' => ReelCollection::query()->withCount('reels')->latest()->limit(6)->get(['id', 'label', 'type', 'created_at']),
        ]);
    }
}
