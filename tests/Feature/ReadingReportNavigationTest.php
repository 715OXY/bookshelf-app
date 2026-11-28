<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReadingReportNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());
        $result = $connection->selectOne('SELECT DATABASE() AS database_name');
        $this->assertSame('testing', $result->database_name);
    }

    // N01
    public function test_会員の両ナビにレポートリンクを表示し現在地を示す(): void
    {
        $this->assertTrue(Route::has('reports.index'));
        $route = Route::getRoutes()->getByName('reports.index');
        $this->assertSame('reports', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth:web', $route->gatherMiddleware());
        $this->actingAs(User::factory()->create(), 'web');

        $report = $this->get(route('reports.index'))->assertOk()->assertViewIs('reports.index');
        $this->assertReportLinks($report, true);
        $index = $this->get(route('books.index'))->assertOk();
        $this->assertReportLinks($index, false);
    }

    // N02
    public function test_ゲストの両ナビにも導線を表示し遷移先は認証で保護する(): void
    {
        $response = $this->get(route('books.index'))
            ->assertOk()
            ->assertSeeText('ログイン')
            ->assertSeeText('新規登録');
        $this->assertReportLinks($response, false);
        $this->assertGuest('web');

        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /**
     * PC・モバイルの各領域でリンクとコンポーネントのactive出力を確認する。
     */
    private function assertReportLinks(TestResponse $response, bool $active): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $url = route('reports.index');

        foreach ([1 => 'border-b-2', 2 => 'border-l-4'] as $region => $border) {
            $links = $xpath->query('//nav/div['.$region.']//a[@href="'.$url.'"]');
            $this->assertSame(1, $links->length);
            $link = $links->item(0);
            $this->assertSame('マイレポート', trim($link->textContent));
            $classes = preg_split('/\s+/', trim($link->getAttribute('class')));
            $this->assertContains($border, $classes);
            $this->assertSame($active, in_array('border-indigo-400', $classes, true));
            $this->assertSame(! $active, in_array('border-transparent', $classes, true));
        }
    }
}
