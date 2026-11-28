<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReadingReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReadingReportController extends Controller
{
    /**
     * 認証済み本人の全レビューを集計し、完全な4統計を表示する。
     * auth:webが認証を保証し、集計対象はRequestの会員に限定する。
     */
    public function index(
        Request $request,
        ReadingReportService $readingReportService
    ): View {
        /** @var User $user */
        $user = $request->user();
        $reviews = $readingReportService->getReviewsFor($user);

        return view('reports.index', [
            'stats' => $readingReportService->getStats($reviews),
        ]);
    }
}
