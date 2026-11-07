<?php

declare(strict_types=1);

namespace App;

/**
 * ジョブキュー。Webからは enqueue、cronワーカーからは claim/complete を使う。
 */
final class Jobs
{
    public const MAX_ATTEMPTS = 3;

    /** ジョブ登録 */
    public static function enqueue(int $videoId, string $stage, array $payload = []): int
    {
        return App::db()->insert('jobs', [
            'video_id' => $videoId,
            'stage'    => $stage,
            'status'   => 'pending',
            'payload'  => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** 次の保留ジョブを1件取得し running に遷移（単一ワーカー前提・ロックで保護） */
    public static function claimNext(): ?array
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $job = $db->fetch(
                "SELECT * FROM jobs WHERE status = 'pending' ORDER BY id LIMIT 1"
            );
            if ($job === null) {
                $db->commit();
                return null;
            }
            $db->update('jobs', [
                'status'     => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'attempts'   => (int) $job['attempts'] + 1,
            ], ['id' => (int) $job['id']]);
            $db->commit();
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['status'] = 'running';
            return $job;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function complete(int $jobId): void
    {
        App::db()->update('jobs', [
            'status'      => 'done',
            'finished_at' => date('Y-m-d H:i:s'),
            'error'       => null,
        ], ['id' => $jobId]);
    }

    /** 失敗。試行上限未満なら pending に戻して再試行可能にする */
    public static function fail(array $job, string $message): bool
    {
        $jobId = (int) $job['id'];
        $attempts = (int) $job['attempts'];
        $willRetry = $attempts < self::MAX_ATTEMPTS;
        App::db()->update('jobs', [
            'status'      => $willRetry ? 'pending' : 'error',
            'error'       => $message,
            'finished_at' => $willRetry ? null : date('Y-m-d H:i:s'),
        ], ['id' => $jobId]);
        return $willRetry;
    }

    public static function payload(array $job): array
    {
        if (empty($job['payload'])) {
            return [];
        }
        $d = json_decode((string) $job['payload'], true);
        return is_array($d) ? $d : [];
    }
}
