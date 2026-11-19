<?php

namespace Tests\Feature\Api\V1;

class ApiRateLimitTest extends BookWriteTestCase
{
    public function test_リクエスト上限を超えると日本語エラーを返す(): void
    {
        $this->freezeTime();

        $before = $this->bookSnapshot();

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.10',
        ]);

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->get(self::URI)
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '60')
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (60 - $attempt)
                );
        }

        $response = $this->get(self::URI)
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertExactJson([
                'message' => 'リクエスト回数が上限を超えました。',
            ]);

        $this->assertTrue($response->headers->has('Retry-After'));

        $retryAfter = $response->headers->get('Retry-After');

        $this->assertTrue(ctype_digit((string) $retryAfter));
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_異なる接続元には独立した制限を適用する(): void
    {
        $this->freezeTime();

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.20',
        ]);

        $this->exhaustRateLimit();

        $this->get(self::URI)->assertStatus(429);

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.21',
        ]);

        $this->get(self::URI)
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');
    }

    public function test_制限時間の経過後は再びアクセスできる(): void
    {
        $this->freezeTime();

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.30',
        ]);

        $this->exhaustRateLimit();

        $this->get(self::URI)->assertStatus(429);

        $this->travel(61)->seconds();

        try {
            $this->get(self::URI)
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '60')
                ->assertHeader('X-RateLimit-Remaining', '59');
        } finally {
            $this->travelBack();
        }
    }

    /**
     * 同一接続元のリクエスト上限を消費する。
     */
    private function exhaustRateLimit(): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->get(self::URI)->assertOk();
        }
    }
}
